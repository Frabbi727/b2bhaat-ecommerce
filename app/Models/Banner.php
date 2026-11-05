<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    public const POSITION_HERO = 'hero_slider';

    public const POSITION_PROMO = 'promo_strip';

    public const POSITION_TOP_STRIP = 'top_strip';

    /** Placements the shop renders, with the names the admin sees. @var array<string, string> */
    public const POSITIONS = [
        self::POSITION_HERO => 'Home page slider',
        self::POSITION_PROMO => 'Home page offer cards',
        self::POSITION_TOP_STRIP => 'Announcement bar',
    ];

    protected $fillable = [
        'title',
        'subtitle',
        'badge_text',
        'desktop_image_url',
        'mobile_image_url',
        'link_url',
        'button_text',
        'position',
        'start_at',
        'end_at',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', $now);
            });
    }

    public function scopeForPosition(Builder $query, string $position): Builder
    {
        return $query->where('position', $position)->orderBy('sort_order')->orderBy('id');
    }

    public function isAvailableNow(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();
        if ($this->start_at && $this->start_at->isFuture()) {
            return false;
        }
        if ($this->end_at && $this->end_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Browser-ready picture address: old banners hold full URLs, uploaded ones hold a path on the public disk.
     *
     * @return Attribute<?string, never>
     */
    protected function desktopImageSrc(): Attribute
    {
        return Attribute::get(fn (): ?string => ProductImage::resolvePublicUrl($this->desktop_image_url));
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function mobileImageSrc(): Attribute
    {
        return Attribute::get(fn (): ?string => ProductImage::resolvePublicUrl($this->mobile_image_url));
    }

    public function liveStatusLabel(): string
    {
        if (! $this->is_active) {
            return 'Turned off';
        }

        if ($this->start_at?->isFuture()) {
            return 'Starts '.$this->start_at->format('d M Y, g:i A');
        }

        if ($this->end_at?->isPast()) {
            return 'Ended '.$this->end_at->format('d M Y');
        }

        return 'Showing in the shop';
    }
}
