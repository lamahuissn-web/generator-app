<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    protected $table = 'routes';

    protected $fillable = ['name', 'notes'];

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class);
    }

    public function activeSubscribers(): HasMany
    {
        return $this->subscribers()->where('status', 'active');
    }
}
