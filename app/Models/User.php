<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builders\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_COLLECTOR = 'collector';

    public const STATUS_ACTIVE = 'active';

    /**
     * A retired account: it cannot sign in and it is not offered for new work, but everything it did
     * keeps its name on it. The alternative — deleting the row — left money rows with
     * `collector_id = null`, which is how a receipt loses the name of whoever collected it.
     */
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['name', 'username', 'email', 'password', 'role', 'phone', 'locale', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locale' => 'string',
        ];
    }

    /**
     * A user created without a username is given one from the address already on the row, so every
     * account can sign in the short way from the day it exists — seeders and factories included.
     *
     * Only an empty one is filled. A name the office chose is left exactly as chosen: renaming a
     * person's own pick behind their back is how two people end up answering to one name they did
     * not choose.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (blank($user->username)) {
                $user->username = static::usernameFor((string) $user->email);
            }
        });
    }

    /**
     * What the person typed at the door, read as one identity: an address if it is shaped like one,
     * otherwise a username. Null when it is neither, so the door refuses in its own words instead of
     * paying for a password check that cannot pass.
     *
     * @return array<string, string>|null
     */
    public static function credentialFor(?string $typed): ?array
    {
        $email = clean_email($typed);

        if (filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email];
        }

        $username = clean_username($typed);

        return $username === '' ? null : ['username' => $username];
    }

    /** The username for an address: its local part, cleaned, made free. */
    public static function usernameFor(string $email, ?int $ignoreId = null): string
    {
        $local = (string) (strstr($email, '@', true) ?: $email);

        return static::freeUsername(clean_username($local), $ignoreId);
    }

    /** A name nobody answers to yet: the base itself, or the base with the first free number after it. */
    public static function freeUsername(string $base, ?int $ignoreId = null): string
    {
        $base = $base !== '' ? $base : 'user';
        $candidate = $base;

        for ($n = 2; static::taken($candidate, $ignoreId); $n++) {
            $candidate = $base.$n;
        }

        return $candidate;
    }

    private static function taken(string $username, ?int $ignoreId): bool
    {
        return static::query()
            ->where('username', $username)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isCollector(): bool
    {
        return $this->role === self::ROLE_COLLECTOR;
    }

    public function isActive(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) !== self::STATUS_INACTIVE;
    }

    public function isInactive(): bool
    {
        return ! $this->isActive();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function enteredReadings(): HasMany
    {
        return $this->hasMany(Reading::class, 'entered_by');
    }

    public function collectedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'collector_id');
    }
}
