<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt from one phone, and the answer it was given.
 *
 * A phone on a bad street retries, and the retry must not become a second receipt. The row is
 * written INSIDE the transaction that writes the money, so the two exist together or not at all: a
 * refusal by the engine rolls this row back with it (the collector fixes the field and sends the
 * same attempt again — an offline queue has no other choice), while a committed receipt leaves the
 * answer here, to be handed back verbatim to every replay of the same key.
 */
class ApiOperation extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMMITTED = 'committed';

    protected $fillable = ['user_id', 'action', 'key', 'request_hash', 'request', 'response', 'status'];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
