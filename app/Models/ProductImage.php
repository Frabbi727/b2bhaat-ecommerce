<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'image_url',
        'is_primary',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Browser-ready URL: seeded absolute URLs pass through; uploaded paths resolve via the public disk (PRA-058).
     *
     * @return Attribute<?string, never>
     */
    protected function publicUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => static::resolvePublicUrl($this->image_url));
    }

    public static function resolvePublicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//', '/', 'data:'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
