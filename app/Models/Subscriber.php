<?php

namespace App\Models;

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

    protected $fillable = [
        'route_id', 'name', 'phone', 'address', 'meter_no', 'type',
        'amperage', 'maintenance_fee', 'flat_fee', 'status', 'notes', 'created_by',
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

    /** Unpaid bills, oldest period first (FIFO payment order). */
    public function unpaidBills()
    {
        return $this->bills()
            ->whereRaw('paid_amount < total')
            ->orderBy('period')
            ->orderBy('id')
            ->get();
    }
}
