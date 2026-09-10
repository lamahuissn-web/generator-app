<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The month's kWh price — Lebanon's Ministry of Energy tariff changes every
 * cycle, so the price is stored per period and used for that period's bills.
 * When a period has no tariff row, BillingService falls back to the global
 * kwh_price setting.
 */
class Tariff extends Model
{
    protected $fillable = ['period', 'kwh_price', 'note', 'created_by'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Price configured for a period, or null when the period has no tariff. */
    public static function priceFor(string $period): ?float
    {
        $price = static::query()->where('period', $period)->value('kwh_price');

        return $price === null ? null : (float) $price;
    }

    /** The tariff row for a period, if any. */
    public static function forPeriod(string $period): ?self
    {
        return static::query()->where('period', $period)->first();
    }

    /** '2026-09' in the active UI language. */
    public function label(): string
    {
        return app()->isLocale('ar') ? period_label_ar($this->period) : period_label($this->period);
    }
}
