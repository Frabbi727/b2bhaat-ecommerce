<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductDailyViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * How many times a product page was opened on one Dhaka calendar day.
 */
class ProductDailyView extends Model
{
    /** @use HasFactory<ProductDailyViewFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'date',
        'views',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'views' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Count one view of the product for today (Dhaka) in a single query that works on PostgreSQL, MySQL and SQLite.
     */
    public static function record(int $productId): void
    {
        $now = now();

        DB::table('product_daily_views')->upsert(
            [[
                'product_id' => $productId,
                'date' => now(config('app.display_timezone'))->toDateString(),
                'views' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['product_id', 'date'],
            ['views' => DB::raw('product_daily_views.views + 1'), 'updated_at' => $now],
        );
    }
}
