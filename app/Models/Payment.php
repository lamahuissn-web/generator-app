<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class Payment extends Model
{
    /** Payment methods the collector picks from. */
    public const METHOD_CASH_USD = 'cash_usd';

    public const METHOD_CASH_LBP = 'cash_lbp';

    public const METHOD_WHISH = 'whish';

    public const METHOD_OMT = 'omt';

    public const METHOD_OTHER = 'other';

    /** @var array<string,string> literal labels, translated through `pay.*` */
    public static array $methods = [
        self::METHOD_CASH_USD => 'Cash (USD)',
        self::METHOD_CASH_LBP => 'Cash (LBP)',
        self::METHOD_WHISH => 'Whish Money',
        self::METHOD_OMT => 'OMT',
        self::METHOD_OTHER => 'Other',
    ];

    protected $fillable = ['number', 'subscriber_id', 'collector_id', 'amount', 'applied', 'method', 'collected_on', 'note', 'lbp_amount', 'lbp_rate'];

    protected function casts(): array
    {
        return [
            'applied' => 'float',
            'lbp_amount' => 'float',
            'lbp_rate' => 'float',
            'collected_on' => 'date',
        ];
    }

    /**
     * Was this money handed over in lira?
     *
     * Null (a dollar collection) and 0 are different: only the first is true of a receipt taken
     * in cash USD, and a stored 0 would make the lira column of a report read as real money.
     */
    public function isLira(): bool
    {
        return $this->lbp_amount !== null;
    }

    /** 'LBP 5,000,000' exactly as it was handed over — never re-converted at today's rate. */
    public function liraLabel(): ?string
    {
        return $this->isLira()
            ? 'LBP '.number_format((float) $this->lbp_amount, 0, '.', ',')
            : null;
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Receipts whose money touched one billing cycle.
     *
     * A payment carries no period of its own — it arrives and is applied to bills, oldest first —
     * so it belongs to a cycle when any invoice it cleared is in that cycle. Written as a scope
     * because two different places need the same answer (the subscriber's file and the receipts
     * table inside it), and a rule written twice is a rule that drifts.
     */
    public function scopeForPeriod(Builder $query, ?string $period): Builder
    {
        if ($period === null) {
            return $query;
        }

        return $query->whereHas(
            'allocations.bill',
            fn (Builder $bill) => $bill->where('period', $period),
        );
    }

    /** Translated label; falls back to the raw value for legacy rows. */
    public static function label(?string $method): string
    {
        $method = $method ?: self::METHOD_CASH_USD;

        return array_key_exists($method, self::$methods) ? __("pay.{$method}") : $method;
    }

    public function methodLabel(): string
    {
        return self::label($this->method);
    }

    /**
     * The day this money was COLLECTED — which is what every report means by a receipt's date.
     *
     * `created_at` is when somebody typed it: a collector with no signal writes his day in at night,
     * and the office entering yesterday's receipts this morning would otherwise file both under
     * today. Falls back to the entry moment only for a row written before the column existed.
     */
    public function collectedOn(): CarbonInterface
    {
        return $this->collected_on ?? $this->created_at ?? now();
    }

    /**
     * Payments COLLECTED inside a range.
     *
     * COALESCE, not a plain `whereBetween('collected_on')`: a row written without a business date
     * (an import, a factory, anything predating the column) must still be counted on the day it was
     * entered rather than silently vanish from the month's figures — a report that drops rows is
     * worse than one that dates them loosely.
     */
    public function scopeCollectedInRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereRaw(
            'DATE(COALESCE(payments.collected_on, payments.created_at)) BETWEEN ? AND ?',
            [$from, $to],
        );
    }

    /** @return array<string,string> for selects/filters */
    public static function methodOptions(): array
    {
        return collect(self::$methods)->keys()->mapWithKeys(fn (string $m) => [$m => self::label($m)])->all();
    }

    /** The bills this payment settled, oldest first. */
    public function settledBills(): Collection
    {
        $this->loadMissing('allocations.bill');

        return $this->allocations
            ->map(fn (PaymentAllocation $allocation) => $allocation->bill)
            ->filter()
            ->values();
    }

    /**
     * What the "remaining balance" on a receipt really is.
     *
     * A flat-rate subscriber who hands over the full amount still walks away owing
     * a number, because the collection itself opens the next cycle's bill and FIFO
     * pays the oldest first — so the receipt must say that the balance is new debt,
     * not arrears the collector forgot to take.
     *
     * @return array{kind: string, new_periods: array<int,string>, leftover_periods: array<int,string>, new_total: float, leftover_total: float}|null
     *                                                                                                                                                null when there is nothing left to explain
     */
    public function remainingNote(): ?array
    {
        $this->loadMissing('allocations.bill');

        $subscriber = $this->subscriber;
        if (! $subscriber) {
            return null;
        }

        $unpaid = $subscriber->unpaidBills();
        if ($unpaid->isEmpty()) {
            return null;
        }

        // The newest cycle this payment reached; anything unpaid past it opened later.
        $cutoff = $this->allocations
            ->map(fn (PaymentAllocation $allocation) => $allocation->bill?->period)
            ->filter()
            ->sort()
            ->last();

        // Nothing was allocated (an unrelated cash entry), so the balance is not ours to explain.
        if ($cutoff === null) {
            return null;
        }

        [$newCycle, $leftover] = $unpaid->partition(fn (Bill $bill) => $bill->period > $cutoff);

        $unpaidAmount = fn (Bill $bill): float => round((float) $bill->total - (float) $bill->paid_amount, 2);
        $newTotal = round($newCycle->sum($unpaidAmount), 2);
        $leftoverTotal = round($leftover->sum($unpaidAmount), 2);

        if ($newCycle->isEmpty()) {
            $kind = 'leftover';
        } elseif ($leftover->isEmpty()) {
            $kind = 'new';
        } else {
            $kind = 'mixed';
        }

        return [
            'kind' => $kind,
            'new_periods' => $newCycle->pluck('period')->unique()->values()->all(),
            'leftover_periods' => $leftover->pluck('period')->unique()->values()->all(),
            'new_total' => $newTotal,
            'leftover_total' => $leftoverTotal,
        ];
    }

    /**
     * Translation key + parameters for the note produced by remainingNote(), so the
     * print views and the collector portal phrase it identically. Periods are labelled
     * by the caller: print output uses Arabic month names in the Arabic UI, the portal
     * keeps the same label style as the line above it.
     *
     * @param  callable(string): string  $periodLabel
     * @return array{0: string, 1: array<string, string>}
     */
    public static function remainingNoteText(array $note, callable $periodLabel): array
    {
        $join = fn (array $periods): string => implode('، ', array_map($periodLabel, $periods));

        return match ($note['kind']) {
            'leftover' => ['prt.remaining_note_leftover', [
                'amount' => money($note['leftover_total']),
                'periods' => $join($note['leftover_periods']),
            ]],
            'mixed' => ['prt.remaining_note_mixed', [
                'amount' => money($note['new_total'] + $note['leftover_total']),
                'leftover' => money($note['leftover_total']),
                'leftover_periods' => $join($note['leftover_periods']),
                'new' => money($note['new_total']),
                'new_periods' => $join($note['new_periods']),
            ]],
            default => ['prt.remaining_note_new', [
                'amount' => money($note['new_total']),
                'periods' => $join($note['new_periods']),
            ]],
        };
    }

    /** 'INV-2026-09-0012 · INV-2026-08-0009', or an em dash when nothing is linked. */
    public function settledBillsLabel(string $separator = ' · '): string
    {
        $labels = $this->settledBills()
            ->map(fn (Bill $bill) => $bill->number ?: 'INV-'.$bill->period)
            ->all();

        return $labels === [] ? '—' : implode($separator, $labels);
    }
}
