<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service done to a device: oil, filter, repair, inspection.
 *
 * `expense_id` links to the money row it produced — the expense stays the single money record, and the centre
 * reads it instead of adding a second figure that would double the month's costs.
 */
class EquipmentService extends Model
{
    use HasFactory;

    public const KIND_OIL = 'oil';
    public const KIND_FILTER = 'filter';
    public const KIND_REPAIR = 'repair';
    public const KIND_INSPECTION = 'inspection';

    protected $fillable = [
        'equipment_id', 'date', 'kind', 'engine_hours', 'cost', 'expense_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'engine_hours' => 'float',
            'cost' => 'float',
        ];
    }

    public static function kinds(): array
    {
        return [
            self::KIND_OIL => __('eq.service_oil'),
            self::KIND_FILTER => __('eq.service_filter'),
            self::KIND_REPAIR => __('eq.service_repair'),
            self::KIND_INSPECTION => __('eq.service_inspection'),
        ];
    }

    public function kindLabel(): string
    {
        return self::kinds()[$this->kind] ?? (string) $this->kind;
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
