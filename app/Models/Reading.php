<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reading extends Model
{
    protected $fillable = [
        'subscriber_id', 'period', 'current_reading', 'previous_reading',
        'consumption', 'entered_by', 'note',
        'is_estimated', 'estimate_reason',
        'old_meter_no', 'old_meter_reading', 'new_meter_no', 'new_meter_start',
    ];

    protected function casts(): array
    {
        return [
            'is_estimated' => 'boolean',
            'old_meter_reading' => 'float',
            'new_meter_start' => 'float',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** The bill generated from this reading (a baseline reading has none). */
    public function bill(): HasOne
    {
        return $this->hasOne(Bill::class);
    }

    /** The meter was replaced during this cycle. */
    public function hasMeterSwap(): bool
    {
        return $this->new_meter_no !== null;
    }

    /** 'قراءة تقديرية — العدّاد معطّل' style label, or null for a real reading. */
    public function sourceLabel(): ?string
    {
        return $this->is_estimated ? __('rdg.estimated_label') : null;
    }

    /**
     * '1123 (نهائي 458) → 4478 (ابتدائي 0)' — the meter change on this cycle, or null.
     */
    public function meterSwapLabel(): ?string
    {
        if (! $this->hasMeterSwap()) {
            return null;
        }

        return __('rdg.swap_pair', [
            'old' => $this->old_meter_no ?: '—',
            'old_reading' => number_format((float) $this->old_meter_reading, 0),
            'new' => $this->new_meter_no,
            'new_start' => number_format((float) $this->new_meter_start, 0),
        ]);
    }
}
