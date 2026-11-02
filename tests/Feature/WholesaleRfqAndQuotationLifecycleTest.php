<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\ProductDetail;
use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WholesaleRfqAndQuotationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);
    }

    public function test_buyer_can_submit_rfq_on_product_detail_page(): void
    {
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::firstOrFail();

        $this->actingAs($buyer);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('rfqBuyerName', 'Boutique Merchant Ltd')
            ->set('rfqBuyerEmail', 'procurement@boutique.com')
            ->set('rfqBuyerPhone', '01711223344')
            ->set('rfqCompanyName', 'Boutique Chain Dhaka')
            ->set('rfqTargetQuantity', 250)
            ->set('rfqTargetPrice', 1850.00)
            ->set('rfqDestinationDistrict', 'Chattogram')
            ->set('rfqSpecifications', 'Requires OEM logo laser etching and batch packaging.')
            ->call('submitRfq')
            ->assertSet('rfqSuccess', true);

        $this->assertDatabaseHas('rfq_requests', [
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'company_name' => 'Boutique Chain Dhaka',
            'target_quantity' => 250,
            'target_unit_price' => 1850.00,
            'destination_district' => 'Chattogram',
            'status' => 'pending',
        ]);
    }

    public function test_seller_can_quote_custom_rate_and_terms(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('seller_id', $seller->id)->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'company_name' => 'Boutique Chain Dhaka',
            'target_quantity' => 300,
            'target_unit_price' => 1200.00,
            'destination_district' => 'Dhaka',
            'status' => 'pending',
        ]);

        $rfq->update([
            'seller_quoted_price' => 1280.00,
            'seller_notes' => 'Quotation valid for 14 days. Free freight delivery to Tejgaon warehouse.',
            'status' => 'quoted',
        ]);

        $this->assertDatabaseHas('rfq_requests', [
            'id' => $rfq->id,
            'seller_quoted_price' => 1280.00,
            'status' => 'quoted',
        ]);
    }

    public function test_quotation_sheet_access_authorization(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $otherUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $product = Product::where('seller_id', $seller->id)->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'company_name' => 'Boutique Chain Dhaka',
            'target_quantity' => 100,
            'target_unit_price' => 2000.00,
            'seller_quoted_price' => 2100.00,
            'destination_district' => 'Dhaka',
            'status' => 'quoted',
        ]);

        // 1. Authorized buyer can view
        $this->actingAs($buyer)
            ->get(route('rfq.quotation.print', $rfq->id))
            ->assertStatus(200)
            ->assertSee('PRO-FORMA QUOTATION')
            ->assertSee('2,100');

        // 2. Assigned seller can view
        $this->actingAs($seller)
            ->get(route('rfq.quotation.print', $rfq->id))
            ->assertStatus(200)
            ->assertSee('PRO-FORMA QUOTATION');

        // 3. Super admin can view
        $this->actingAs($admin)
            ->get(route('rfq.quotation.print', $rfq->id))
            ->assertStatus(200);

        // 4. Unrelated customer receives HTTP 403 Forbidden
        $this->actingAs($otherUser)
            ->get(route('rfq.quotation.print', $rfq->id))
            ->assertStatus(403);
    }

    public function test_buyer_can_accept_quote_and_convert_to_wholesale_cart_item(): void
    {
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $product = Product::where('seller_id', $seller->id)->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'company_name' => 'Boutique Chain Dhaka',
            'target_quantity' => 150,
            'target_unit_price' => 1400.00,
            'seller_quoted_price' => 1450.00,
            'destination_district' => 'Dhaka',
            'status' => 'quoted',
        ]);

        $cartService = app(CartSessionService::class);
        $cartService->clear();

        $this->actingAs($buyer)
            ->post(route('rfq.quotation.accept', $rfq->id))
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHas('success');

        $this->assertEquals('accepted', $rfq->fresh()->status);

        // Verify cart items contains wholesale item with agreed quoted unit price
        $items = $cartService->getItems();
        $this->assertNotEmpty($items);

        $cartItem = reset($items);
        $this->assertEquals($product->id, $cartItem['product_id']);
        $this->assertEquals(SalesChannel::WHOLESALE, $cartItem['sales_channel_slug']);
        $this->assertEquals(150, $cartItem['quantity']);
        $this->assertEquals(1450.00, $cartItem['unit_price']);
    }
}
