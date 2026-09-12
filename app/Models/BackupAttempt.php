<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One backup run.
 *
 * The model owns the shape of the record and nothing else: no wording, no thresholds, no decisions
 * about whether the office is covered — those live in App\Services\Backup\BackupSettings, which the
 * screen, the command and the chat message all read, so the panel cannot tell the office one thing
 * while the chat says another.
 */
class BackupAttempt extends Model
{
    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'started_at', 'finished_at', 'trigger', 'status',
        'file_name', 'size_bytes', 'parts', 'error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'size_bytes' => 'integer',
            'parts' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Newest first — the order the screen and the "what happened last" questions both want. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /** The newest copy the office actually has in its chat, or null when it has none. */
    public static function lastSent(): ?self
    {
        return static::query()->sent()->latestFirst()->first();
    }

    public function delivered(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /** The size as a person reads it: '2.4 MB'. Used by the screen, the chat caption and the log. */
    public function sizeForHumans(): string
    {
        return self::humanSize($this->size_bytes);
    }

    /**
     * Bytes as a person reads them: '2.4 MB'.
     *
     * Latin digits and a Latin unit in both languages, deliberately: the office compares this figure
     * against what the phone shows in the chat, and the two have to look like the same number.
     */
    public static function humanSize(int|string|null $bytes): string
    {
        $bytes = (int) ($bytes ?? 0);

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, '.', '').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, '.', '').' MB';
    }

    public function triggerLabel(): string
    {
        return $this->trigger === self::TRIGGER_MANUAL ? __('bk.trigger_manual') : __('bk.trigger_scheduled');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => __('bk.status_sent'),
            self::STATUS_FAILED => __('bk.status_failed'),
            default => __('bk.status_running'),
        };
    }

    /** Tone for the badge, in the panel's own vocabulary. */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'warning',
        };
    }
}
