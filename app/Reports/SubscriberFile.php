<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Builder;

/**
 * One subscriber's account, read as of ONE cycle — or as of everything.
 *
 * The panel could only ever answer "what does this house owe" as a single all-time number, and
 * only from inside the bills tab. So the three questions that actually settle a phone call —
 * what was billed, what was paid, what is still owed — were nowhere together, and "how did
 * August go for this house?" meant scrolling three separate lists and adding them up by hand.
 *
 * This is the only place the scoping rules are written down, so the cards, the three logs and
 * the counts in their headers cannot drift apart: they are all views of the same scope.
 *
 * prev_debt is deliberately NOT added to the billed figure. It is the SAME money as an earlier
 * cycle's unpaid bill, carried on the new one for the reader's benefit (BillingService keeps it
 * informational, exactly like the printed bill); counting it again would inflate the debt the
 * moment a bill is generated, once per cycle, forever.
 */
final class SubscriberFile
{
    public function __construct(
        private readonly Subscriber $subscriber,
        private readonly ?string $period = null,
    ) {
    }

    public function subscriber(): Subscriber
    {
        return $this->subscriber;
    }

    /** The cycle this file is read as of; null means the whole account. */
    public function period(): ?string
    {
        return $this->period;
    }

    public function isScoped(): bool
    {
        return $this->period !== null;
    }

    /**
     * The cycles this file can be read as of, newest first.
     *
     * Taken from the money and the meters rather than offered as a fixed window: every cycle
     * that has a bill or a reading for THIS house is a cycle somebody will ask about, and the
     * list stays short because it is one subscriber's history, not the panel's calendar.
     *
     * @return array<string, string> 'Y-m' => label
     */
    public function periods(): array
    {
        $keys = array_unique(array_merge(
            $this->subscriber->bills()->distinct()->pluck('period')->all(),
            $this->subscriber->readings()->distinct()->pluck('period')->all(),
            [BillingService::currentPeriod()],
        ));

        rsort($keys);

        $keys = array_values($keys);

        return array_combine($keys, array_map(fn (string $key): string => period_label_i18n($key), $keys));
    }

    // ---- the three numbers ----------------------------------------------------

    /**
     * What the cards show, plus what the scope leaves OUT.
     *
     * A cycle-scoped view that only reported the cycle would let a file look square while another
     * cycle sits unpaid — the exact mistake that starts an argument at the door. So the account's
     * whole debt is computed as well, and the difference is reported as its own figure. That
     * difference is deliberately NOT called "previous debt": it is every cycle the scope left out,
     * which can be older (a month nobody paid) or newer (a bill already issued for next month).
     *
     * @return array{billed: float, paid: float, due: float, other_cycles_due: float, all_due: float}
     */
    public function totals(): array
    {
        $row = $this->billsQuery()
            ->selectRaw('COALESCE(SUM(total), 0) as billed, COALESCE(SUM(paid_amount), 0) as paid')
            ->first();

        $billed = round((float) ($row->billed ?? 0), 2);
        $paid = round((float) ($row->paid ?? 0), 2);
        $due = round($billed - $paid, 2);
        $allDue = round($this->subscriber->outstanding(), 2);

        return [
            'billed' => $billed,
            'paid' => $paid,
            'due' => $due,
            'other_cycles_due' => round($allDue - $due, 2),
            'all_due' => $allDue,
        ];
    }

    /**
     * How many records each log holds in this scope — the badges the office reads before a list.
     *
     * @return array{bills: int, readings: int, payments: int}
     */
    public function counts(): array
    {
        return [
            'bills' => $this->billsQuery()->count(),
            'readings' => $this->readingsQuery()->count(),
            'payments' => $this->paymentsQuery()->count(),
        ];
    }

    /** Unpaid bills across the WHOLE account (the honest answer to "شو عليه؟"). */
    public function openBillCount(): int
    {
        return $this->subscriber->bills()->whereRaw('paid_amount < total')->count();
    }

    // ---- the three logs -------------------------------------------------------

    public function billsQuery(): Builder
    {
        $query = Bill::query()->where('subscriber_id', $this->subscriber->getKey());

        return $this->period === null ? $query : $query->where('period', $this->period);
    }

    public function readingsQuery(): Builder
    {
        $query = Reading::query()->where('subscriber_id', $this->subscriber->getKey());

        return $this->period === null ? $query : $query->where('period', $this->period);
    }

    /**
     * Receipts, narrowed to the money that touched the scope.
     *
     * A payment is not stamped with a cycle — it arrives and is applied to bills, oldest first —
     * so a receipt belongs to this cycle when any invoice it cleared is in it. That is also the
     * only affordable question: a receipt of $200 that cleared June, July and August did not
     * happen "in August", it happened TO August as well.
     */
    public function paymentsQuery(): Builder
    {
        // The scope itself lives on the model: the receipts table inside the file asks the same
        // question, and two copies of "what does this cycle mean for a payment" would drift.
        return Payment::query()
            ->where('subscriber_id', $this->subscriber->getKey())
            ->forPeriod($this->period);
    }
}
