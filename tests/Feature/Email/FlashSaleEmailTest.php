<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Events\Promotions\FlashSaleSubmissionDecidedEvent;
use App\Mail\Seller\FlashSaleSubmissionStatusMail;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FlashSaleEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_receives_email_when_flash_sale_product_is_decided(): void
    {
        Mail::fake();

        $seller = User::factory()->create(['email' => 'flashseller@factory.com', 'name' => 'Flash Seller']);
        $category = Category::firstOrCreate(['slug' => 'test-cat'], ['name' => 'Test Cat']);
        $channel = SalesChannel::firstOrCreate(['slug' => 'both'], ['name' => 'Both']);
        $unit = Unit::firstOrCreate(['name' => 'Pcs'], ['name' => 'Pcs', 'symbol' => 'pcs']);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => $channel->id,
            'unit_id' => $unit->id,
            'title' => 'Cotton Shirt',
            'slug' => 'cotton-shirt-test-99',
            'regular_price' => 1000.00,
            'buy_price' => 700.00,
            'is_active' => true,
        ]);

        $campaign = FlashSale::factory()->create(['name' => 'Winter Mega Sale']);

        $submission = FlashSaleProduct::create([
            'flash_sale_id' => $campaign->id,
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'original_retail_price' => 1000.00,
            'campaign_price' => 750.00,
            'stock_limit' => 50,
            'status' => 'approved',
        ]);

        event(new FlashSaleSubmissionDecidedEvent($submission, 'approved'));

        Mail::assertQueued(FlashSaleSubmissionStatusMail::class, function (FlashSaleSubmissionStatusMail $mail) {
            return $mail->hasTo('flashseller@factory.com');
        });
    }
}
