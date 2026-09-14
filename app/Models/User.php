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

    protected $fillable = ['name', 'email', 'password', 'role', 'phone', 'locale', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locale' => 'string',
        ];
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
