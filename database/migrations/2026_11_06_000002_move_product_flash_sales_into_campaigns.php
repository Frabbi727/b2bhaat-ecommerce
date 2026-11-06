<?php

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'is_flash_sale')) {
            $flaggedProducts = DB::table('products')
                ->where('is_flash_sale', true)
                ->where('flash_sale_price', '>', 0)
                ->get();

            if ($flaggedProducts->isNotEmpty()) {
                $groups = $flaggedProducts->groupBy(function ($product) {
                    return $product->flash_sale_end ? (string) $product->flash_sale_end : 'default';
                });

                foreach ($groups as $endKey => $productsInGroup) {
                    $endsAt = $endKey !== 'default'
                        ? Carbon::parse($endKey)
                        : now()->addDays(7);

                    $dhakaDate = $endsAt->copy()->timezone('Asia/Dhaka')->format('d M Y, h:i A');
                    $baseSlug = Str::slug("flash-sale-until-{$endsAt->timestamp}");
                    $slug = $baseSlug;
                    $counter = 1;
                    while (DB::table('flash_sales')->where('slug', $slug)->exists()) {
                        $slug = "{$baseSlug}-{$counter}";
                        $counter++;
                    }

                    $campaignId = DB::table('flash_sales')->insertGetId([
                        'name' => "Flash sale until {$dhakaDate}",
                        'slug' => $slug,
                        'status' => FlashSaleStatus::Scheduled->value,
                        'starts_at' => now(),
                        'ends_at' => $endsAt,
                        'is_featured' => false,
                        'display_priority' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach ($productsInGroup as $prod) {
                        DB::table('flash_sale_products')->insert([
                            'flash_sale_id' => $campaignId,
                            'product_id' => $prod->id,
                            'discount_type' => FlashSaleDiscountType::FixedPrice->value,
                            'discount_value' => $prod->flash_sale_price,
                            'stock_limit' => null,
                            'per_customer_limit' => null,
                            'sort_order' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn(['is_flash_sale', 'flash_sale_price', 'flash_sale_end']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_flash_sale')->default(false)->after('is_featured');
            $table->decimal('flash_sale_price', 12, 2)->nullable()->after('is_flash_sale');
            $table->timestamp('flash_sale_end')->nullable()->after('flash_sale_price');
        });
    }
};
