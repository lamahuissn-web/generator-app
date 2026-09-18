<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One diesel purchase — the owner's whole routine, two fields: litres and price.
 *
 * `kwh_produced` is optional on purpose: when the owner writes down the kWh the machine produced since the
 * last refuelling, the row is `measured`; when he does not, the centre uses litres × the kWh/litre rate the
 * office set once and the row stays `estimated`. The distinction is stored so an estimate can never be
 * mistaken for a measurement later.
 */
class Refuelling extends Model
{
    use HasFactory;

    public const SOURCE_MEASURED = 'measured';
    public const SOURCE_ESTIMATED = 'estimated';

    protected $fillable = [
        'equipment_id', 'date', 'litres', 'price_per_litre', 'engine_hours',
        'kwh_produced', 'kwh_source', 'supplier', 'expense_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'litres' => 'float',
            'price_per_litre' => 'float',
            'engine_hours' => 'float',
            'kwh_produced' => 'float',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function isMeasured(): bool
    {
        return $this->kwh_source === self::SOURCE_MEASURED && (float) $this->kwh_produced > 0;
    }

    /** What this purchase cost: litres × the price on the receipt. */
    public function money(): float
    {
        return round((float) $this->litres * (float) $this->price_per_litre, 2);
    }
}
