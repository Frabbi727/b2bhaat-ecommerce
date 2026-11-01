<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class StoreSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'group',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("store_setting_{$key}", 3600, function () use ($key, $default) {
            $record = static::where('key', $key)->first();

            return $record?->value ?? $default;
        });
    }

    public static function set(string $key, mixed $value, string $group = 'general'): self
    {
        $record = static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'group' => $group]
        );

        Cache::forget("store_setting_{$key}");

        return $record;
    }
}
