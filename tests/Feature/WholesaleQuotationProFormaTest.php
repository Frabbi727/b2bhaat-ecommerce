<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholesaleQuotationProFormaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_seller_can_view_official_quotation_sheet(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => 'Farhana Akter',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '+8801700000003',
            'company_name' => 'Boutique Chains Ltd.',
            'target_quantity' => 500,
            'target_unit_price' => 90.00,
            'seller_quoted_price' => 88.00,
            'destination_district' => 'Dhaka',
            'specifications' => 'Matte finish with custom gold foil boutique box packaging.',
            'seller_notes' => 'Production requires 5 days. Delivered via Steadfast express courier.',
            'status' => 'quoted',
        ]);

        $this->actingAs($seller);

        $response = $this->get(route('rfq.quotation.print', $rfq->id));

        $response->assertStatus(200);
        $response->assertSee('PRO-FORMA QUOTATION');
        $response->assertSee('QT-RFQ-'.str_pad($rfq->id, 6, '0', STR_PAD_LEFT));
        $response->assertSee('Boutique Chains Ltd.');
        $response->assertSee('Matte finish with custom gold foil boutique box packaging.');
        $response->assertSee('৳88.00');
        $response->assertSee('৳44,000.00'); // 500 * 88
        $response->assertSee('Production requires 5 days');
        $response->assertSee('Official Corporate Seal');
    }

    public function test_buyer_can_view_their_official_quotation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => 'Farhana Akter',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '+8801700000003',
            'company_name' => 'Boutique Chains Ltd.',
            'target_quantity' => 200,
            'target_unit_price' => 95.00,
            'seller_quoted_price' => 95.00,
            'destination_district' => 'Chittagong',
            'status' => 'quoted',
        ]);

        $this->actingAs($buyer);

        $response = $this->get(route('rfq.quotation.print', $rfq->id));

        $response->assertStatus(200);
        $response->assertSee('PRO-FORMA QUOTATION');
        $response->assertSee('Farhana Akter');
        $response->assertSee('Chittagong District');
    }

    public function test_unauthorized_user_cannot_view_quotation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => 'Farhana Akter',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '+8801700000003',
            'company_name' => 'Boutique Chains Ltd.',
            'target_quantity' => 100,
            'status' => 'pending',
        ]);

        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($retailUser);

        $response = $this->get(route('rfq.quotation.print', $rfq->id));

        $response->assertStatus(403);
    }

    public function test_guest_is_redirected_from_quotation(): void
    {
        $product = Product::firstOrFail();
        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $product->seller_id,
            'buyer_name' => 'Guest Buyer',
            'buyer_email' => 'guest@example.com',
            'buyer_phone' => '+8801700000000',
            'target_quantity' => 100,
            'status' => 'pending',
        ]);

        $response = $this->get(route('rfq.quotation.print', $rfq->id));

        $response->assertRedirect();
    }

    public function test_super_admin_can_view_any_quotation(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'test@buyer.com',
            'buyer_phone' => '+8801700000000',
            'target_quantity' => 300,
            'status' => 'quoted',
        ]);

        $this->actingAs($admin);

        $response = $this->get(route('rfq.quotation.print', $rfq->id));

        $response->assertStatus(200);
        $response->assertSee('PRO-FORMA QUOTATION');
        $response->assertSee('QT-RFQ-'.str_pad($rfq->id, 6, '0', STR_PAD_LEFT));
    }
}
