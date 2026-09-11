<?php

namespace App\Models;

use App\Services\Audit;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscriber extends Model
{
    public const TYPE_METERED = 'metered';

    public const TYPE_FLAT = 'flat_rate';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const MAINT_AUTO = 'auto';

    public const MAINT_CUSTOM = 'custom';

    public const MAINT_EXEMPT = 'exempt';

    protected $fillable = [
        'route_id', 'name', 'phone', 'address', 'meter_no', 'type',
        'amperage', 'maintenance_fee', 'maintenance_mode', 'flat_fee', 'status', 'notes', 'created_by',
        'meter_broken_reported_at', 'meter_broken_note', 'meter_broken_estimates', 'meter_broken_reported_by',
    ];

    protected function casts(): array
    {
        return [
            'meter_broken_reported_at' => 'datetime',
        ];
    }

    /** Every new subscriber starts in "custom" mode unless told otherwise. */
    protected $attributes = [
        'maintenance_mode' => self::MAINT_CUSTOM,
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Who reported the dead meter from the field (the case's other side). */
    public function meterBrokenReportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'meter_broken_reported_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function scopeMetered(Builder $q): Builder
    {
        return $q->where('type', self::TYPE_METERED);
    }

    public function isMetered(): bool
    {
        return $this->type === self::TYPE_METERED;
    }

    public function isFlat(): bool
    {
        return $this->type === self::TYPE_FLAT;
    }

    /**
     * Monthly maintenance fee actually billed for this subscriber:
     * custom = their own amount, exempt = nothing, auto = the global default.
     */
    public function effectiveMaintenanceFee(): float
    {
        return match ($this->maintenance_mode) {
            self::MAINT_EXEMPT => 0.0,
            self::MAINT_CUSTOM => (float) $this->maintenance_fee,
            default => BillingService::defaultMaintenanceFee(),
        };
    }

    /** Latest recorded reading (any period). */
    public function latestReading(): ?Reading
    {
        return $this->readings()->orderByDesc('period')->orderByDesc('id')->first();
    }

    /** Reading recorded for a specific period, if any. */
    public function readingFor(string $period): ?Reading
    {
        return $this->readings()->where('period', $period)->first();
    }

    /** Total outstanding across all unpaid bills (total - paid). */
    public function outstanding(): float
    {
        return (float) $this->bills()
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as o')
            ->value('o');
    }

    /**
     * Unpaid bills, oldest period first (FIFO payment order).
     *
     * Within one period the SUBSCRIPTION bill comes before special invoices: a collector
     * who takes exactly the monthly fee must clear the month, not a part that happens to
     * carry a lower id.
     */
    public function unpaidBills()
    {
        return $this->bills()
            ->whereRaw('paid_amount < total')
            ->orderBy('period')
            ->orderByRaw("type = '".Bill::TYPE_SPECIAL."'")
            ->orderBy('id')
            ->get();
    }

    /** A collector flagged the meter as dead (nothing decided yet). */
    public function hasBrokenMeterReport(): bool
    {
        return $this->meter_broken_reported_at !== null;
    }

    /**
     * Field report from the collector: the meter is dead. The admin decides what next.
     *
     * Idempotent on purpose. A second report on a live case refreshes the note but keeps the
     * original date and the estimates already billed against it: the count is what tells the
     * office this case has been running for months, and resetting it by re-reporting would be
     * the easiest way for a stuck case to look brand new again.
     */
    public function reportMeterBroken(?string $note = null, ?int $userId = null): void
    {
        $alreadyOpen = $this->hasBrokenMeterReport();

        $this->forceFill([
            'meter_broken_reported_at' => $alreadyOpen ? $this->meter_broken_reported_at : now(),
            'meter_broken_note' => $note,
            'meter_broken_reported_by' => $userId,
        ])->save();

        Audit::log('subscriber.meter_reported_broken', $this, [
            'note' => $note,
            'by' => $userId,
            'already_open' => $alreadyOpen,
            'estimates_already_billed' => (int) $this->meter_broken_estimates,
        ]);
    }

    /** How many estimated cycles have been billed while this meter has been dead. */
    public function brokenEstimatesUsed(): int
    {
        return (int) $this->meter_broken_estimates;
    }

    /**
     * May one more estimated cycle be billed against this case?
     *
     * The policy is one estimate per dead meter: it answers the cycle the report arrived in,
     * and a second estimated month means nobody has decided anything about the device. True when
     * no case is open at all — an estimate for a meter that is fine (nobody home, locked gate)
     * is an ordinary administrative choice, not this policy's business.
     */
    public function mayEstimateAgain(): bool
    {
        return ! $this->hasBrokenMeterReport() || $this->brokenEstimatesUsed() === 0;
    }

    /** One more estimated cycle has been billed against the open case. */
    public function recordBrokenEstimate(): void
    {
        if (! $this->hasBrokenMeterReport()) {
            return;
        }

        $this->forceFill(['meter_broken_estimates' => $this->brokenEstimatesUsed() + 1])->save();
    }

    /**
     * The case is closed: the meter was replaced, read while working, or the subscriber moved
     * to a flat rate. Clearing the counter is what lets the NEXT dead meter start clean.
     */
    public function clearMeterReport(): void
    {
        $this->forceFill([
            'meter_broken_reported_at' => null,
            'meter_broken_note' => null,
            'meter_broken_estimates' => 0,
            'meter_broken_reported_by' => null,
        ])->save();
    }

    /**
     * The meter is gone for good: bill a fixed monthly amount instead. The released
     * meter number and the reason stay in the audit trail.
     */
    public function convertToFlat(float $flatFee, string $reason, ?int $userId = null): void
    {
        if ($this->isFlat()) {
            throw new \RuntimeException(__('refuse.already_flat'));
        }

        $released = $this->meter_no;

        $this->forceFill([
            'type' => self::TYPE_FLAT,
            'flat_fee' => round($flatFee, 2),
            'meter_no' => null,
            'amperage' => null,
            'maintenance_fee' => 0,
            'maintenance_mode' => self::MAINT_CUSTOM,
            'meter_broken_reported_at' => null,
            'meter_broken_note' => null,
            'meter_broken_estimates' => 0,
            'meter_broken_reported_by' => null,
        ])->save();

        Audit::log('subscriber.converted_to_flat', $this, [
            'reason' => $reason,
            'flat_fee' => round($flatFee, 2),
            'released_meter' => $released,
            'by' => $userId,
        ]);
    }

    /**
     * Put a meter back on the subscriber. The starting reading is recorded as a baseline
     * (no charge) — without it the first cycle would bill the meter's whole value.
     */
    public function convertToMetered(
        string $meterNo,
        float $initialReading,
        ?int $amperage = null,
        ?float $maintenanceFee = null,
        ?string $reason = null,
        ?string $period = null,
        ?int $userId = null,
    ): Reading {
        if ($this->isMetered()) {
            throw new \RuntimeException(__('refuse.already_metered'));
        }

        $period ??= BillingService::currentPeriod();

        $this->forceFill([
            'type' => self::TYPE_METERED,
            'flat_fee' => null,
            'meter_no' => $meterNo ?: null,
            'amperage' => $amperage,
            'maintenance_fee' => $maintenanceFee ?? BillingService::defaultMaintenanceFee(),
            'maintenance_mode' => $maintenanceFee === null ? self::MAINT_AUTO : self::MAINT_CUSTOM,
            'meter_broken_reported_at' => null,
            'meter_broken_note' => null,
            'meter_broken_estimates' => 0,
            'meter_broken_reported_by' => null,
        ])->save();

        $reading = BillingService::recordBaselineReading($this, $period, $initialReading, $userId);

        Audit::log('subscriber.converted_to_metered', $this, [
            'reason' => $reason,
            'meter_no' => $meterNo,
            'initial_reading' => round($initialReading, 2),
            'period' => $period,
            'by' => $userId,
        ]);

        return $reading;
    }
}
