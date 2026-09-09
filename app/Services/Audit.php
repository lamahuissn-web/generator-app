<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    /** Record a financial/operational action with who did it and the before/after context. */
    public static function log(string $action, ?\Illuminate\Database\Eloquent\Model $subject = null, array $context = []): void
    {
        // Skip while seeding (CLI, not tests) so demo data doesn't drown the trail.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'payload' => $context,
        ]);
    }
}
