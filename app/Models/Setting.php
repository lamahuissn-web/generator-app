<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("setting.{$key}", fn () => optional(
            static::where('key', $key)->first()
        )->value) ?? $default;
    }

    public static function set(string $key, ?string $value, ?string $description = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'description' => $description]);
        Cache::forget("setting.{$key}");
    }

    public static function flush(): void
    {
        Cache::flush();
    }
}
