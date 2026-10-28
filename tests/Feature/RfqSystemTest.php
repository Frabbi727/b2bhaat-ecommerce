<?php

namespace Tests\Feature;

use App\Livewire\Storefront\ProductDetail;
use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RfqSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_buyer_can_submit_custom_factory_rfq(): void
    {
        $wholesaleUser = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->actingAs($wholesaleUser);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->call('openRfqModal')
            ->assertSet('showRfqModal', true)
            ->set('rfqTargetQuantity', 1000)
            ->set('rfqTargetPrice', 85.00)
            ->set('rfqBuyerName', 'Rahima Begum')
            ->set('rfqBuyerEmail', 'buyer@boutique.com')
            ->set('rfqBuyerPhone', '+8801700000003')
            ->set('rfqCompanyName', 'Chic Boutique Chains')
            ->set('rfqDestinationDistrict', 'Chittagong')
            ->set('rfqSpecifications', 'Need 1,000 sets in custom pink matte carton with boutique gold logo stamping.')
            ->call('submitRfq')
            ->assertHasNoErrors()
            ->assertSet('rfqSuccess', true)
            ->assertSee('RFQ Inquiry Submitted!');

        $rfq = RfqRequest::where('product_id', $product->id)->firstOrFail();
        $this->assertEquals(1000, $rfq->target_quantity);
        $this->assertEquals(85.00, (float) $rfq->target_unit_price);
        $this->assertEquals('pending', $rfq->status);
        $this->assertEquals($product->seller_id, $rfq->seller_id);
    }

    public function test_rfq_validates_bangladeshi_phone_and_quantity(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('rfqBuyerPhone', 'bad-phone-number')
            ->set('rfqTargetQuantity', 0)
            ->call('submitRfq')
            ->assertHasErrors(['rfqBuyerPhone', 'rfqTargetQuantity']);
    }

    public function test_seller_can_review_and_dispatch_custom_quote(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => 'Rahima Begum',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '+8801700000003',
            'company_name' => 'Chic Boutique Chains',
            'target_quantity' => 1000,
            'target_unit_price' => 85.00,
            'destination_district' => 'Chittagong',
            'specifications' => 'Custom packaging requested',
            'status' => 'pending',
        ]);

        $this->assertEquals('pending', $rfq->status);

        // Seller sends quotation
        $rfq->update([
            'seller_quoted_price' => 90.00,
            'seller_notes' => 'We can manufacture 1,000 units at ৳90/unit with custom gold embossing within 7 working days.',
            'status' => 'quoted',
        ]);

        $rfq->refresh();
        $this->assertEquals('quoted', $rfq->status);
        $this->assertEquals(90.00, (float) $rfq->seller_quoted_price);
        $this->assertStringContainsString('custom gold embossing', $rfq->seller_notes);
    }
}
