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
        'type',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public static function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'decimal' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => is_string($value) ? (json_decode($value, true) ?? []) : (array) $value,
            default => (string) $value,
        };
    }

    public static function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'json', 'array' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::getTypedValue($key, $default);
    }

    public static function getTypedValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember("store_setting_{$key}", 3600, function () use ($key, $default) {
            $record = static::where('key', $key)->first();

            if (! $record) {
                return $default;
            }

            return static::castValue($record->value, $record->type ?? 'string');
        });
    }

    public static function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): self
    {
        return static::setTypedValue($key, $value, $group, $type);
    }

    public static function setTypedValue(
        string $key,
        mixed $value,
        string $group = 'general',
        string $type = 'string',
        bool $isPublic = true
    ): self {
        $serialized = static::serializeValue($value, $type);

        $record = static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $serialized,
                'group' => $group,
                'type' => $type,
                'is_public' => $isPublic,
            ]
        );

        Cache::forget("store_setting_{$key}");
        Cache::forget('store_settings_all_grouped');

        return $record;
    }
}
