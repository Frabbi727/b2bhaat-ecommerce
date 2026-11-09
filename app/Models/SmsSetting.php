<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Services\Audit\AuditRedactor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsSetting extends Model
{
    use Auditable {
        recordAuditActivity as protected recordUnmaskedAuditActivity;
    }
    use HasFactory;

    /**
     * AUD-4: the value of a secret setting (API key, fallback code) is masked; the entry still shows it changed.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function recordAuditActivity(string $action, ?array $old, ?array $new): void
    {
        if (app(AuditRedactor::class)->isSensitiveKey((string) $this->key)) {
            foreach (['old', 'new'] as $side) {
                if (is_array($$side) && array_key_exists('value', $$side)) {
                    $$side['value'] = '[REDACTED]';
                }
            }
        }

        $this->recordUnmaskedAuditActivity($action, $old, $new);
    }

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }
}
