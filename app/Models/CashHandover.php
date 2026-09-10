<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash a collector physically handed to the operator. Collections minus
 * handovers is what the collector still owes in cash (see CollectionService).
 */
class CashHandover extends Model
{
    protected $fillable = [
        'collector_id', 'amount', 'handover_date', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'handover_date' => 'date',
        ];
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
