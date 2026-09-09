<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reading extends Model
{
    protected $fillable = [
        'subscriber_id', 'period', 'current_reading', 'previous_reading',
        'consumption', 'entered_by', 'note',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'reading_id');
    }
}
