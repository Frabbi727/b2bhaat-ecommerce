<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\CustomerRfqs;
use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\User;
use App\Services\Cart\CartSessionService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerRfqsTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $otherBuyer;

    private User $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();

        $this->otherBuyer = User::factory()->create([
            'name' => 'Other Merchant',
            'email' => 'other@merchant.com',
            'phone' => '+8801700999888',
        ]);

        $this->product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
    }

    public function test_guest_is_redirected_to_login_when_accessing_my_rfqs(): void
    {
        $response = $this->get('/my-rfqs');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_buyer_can_access_my_rfqs_and_see_their_rfqs(): void
    {
        $rfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'company_name' => 'Boutique Chains Ltd.',
            'target_quantity' => 200,
            'target_unit_price' => 95.00,
            'seller_quoted_price' => 92.50,
            'destination_district' => 'Dhaka',
            'specifications' => 'Glossy finish with custom barcode.',
            'status' => 'quoted',
            'dispatch_days' => 5,
            'valid_until' => now()->addDays(7),
        ]);

        $this->actingAs($this->buyer);

        $response = $this->get('/my-rfqs');
        $response->assertOk();
        $response->assertSee('My Quotations');
        $response->assertSee($this->product->title);
        $response->assertSee('92.50');
        $response->assertSee('200');
    }

    public function test_buyer_cannot_see_other_buyers_rfqs_tenant_isolation(): void
    {
        $buyerRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'company_name' => 'Boutique Chains Ltd.',
            'target_quantity' => 150,
            'target_unit_price' => 100.00,
            'status' => 'pending',
            'destination_district' => 'Dhaka',
        ]);

        $otherRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->otherBuyer->id,
            'buyer_name' => $this->otherBuyer->name,
            'buyer_email' => $this->otherBuyer->email,
            'buyer_phone' => $this->otherBuyer->phone,
            'company_name' => 'Confidential Corp',
            'target_quantity' => 9999,
            'target_unit_price' => 50.00,
            'status' => 'pending',
            'destination_district' => 'Chittagong',
            'specifications' => 'Top secret proprietary packaging',
        ]);

        $this->actingAs($this->buyer);

        Livewire::test(CustomerRfqs::class)
            ->assertSee('Boutique Chains Ltd.')
            ->assertSee('150')
            ->assertDontSee('Confidential Corp')
            ->assertDontSee('Top secret proprietary packaging');
    }

    public function test_rfq_matched_by_buyer_email_is_visible_to_buyer(): void
    {
        $rfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => null,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => strtolower($this->buyer->email),
            'buyer_phone' => $this->buyer->phone,
            'company_name' => 'Email Linked Corp',
            'target_quantity' => 300,
            'target_unit_price' => 88.00,
            'status' => 'pending',
            'destination_district' => 'Sylhet',
        ]);

        $this->actingAs($this->buyer);

        Livewire::test(CustomerRfqs::class)
            ->assertSee('Email Linked Corp')
            ->assertSee('300');
    }

    public function test_filter_tabs_work_correctly(): void
    {
        $pendingRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 101,
            'target_unit_price' => 90.00,
            'status' => 'pending',
            'destination_district' => 'Dhaka',
            'specifications' => 'PENDING_SPEC_UNIQUE',
        ]);

        $quotedRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 202,
            'target_unit_price' => 90.00,
            'seller_quoted_price' => 85.00,
            'valid_until' => now()->addDays(5),
            'status' => 'quoted',
            'destination_district' => 'Dhaka',
            'specifications' => 'QUOTED_SPEC_UNIQUE',
        ]);

        $acceptedRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 303,
            'target_unit_price' => 90.00,
            'seller_quoted_price' => 80.00,
            'status' => 'accepted',
            'destination_district' => 'Dhaka',
            'specifications' => 'ACCEPTED_SPEC_UNIQUE',
        ]);

        $rejectedRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 404,
            'target_unit_price' => 90.00,
            'status' => 'rejected',
            'destination_district' => 'Dhaka',
            'rejected_by' => 'buyer',
            'specifications' => 'REJECTED_SPEC_UNIQUE',
        ]);

        $expiredRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 505,
            'target_unit_price' => 90.00,
            'seller_quoted_price' => 75.00,
            'valid_until' => now()->subDay(),
            'status' => 'quoted',
            'destination_district' => 'Dhaka',
            'specifications' => 'EXPIRED_SPEC_UNIQUE',
        ]);

        $this->actingAs($this->buyer);

        // All tab
        Livewire::test(CustomerRfqs::class)
            ->set('tab', 'all')
            ->assertSee('PENDING_SPEC_UNIQUE')
            ->assertSee('QUOTED_SPEC_UNIQUE')
            ->assertSee('ACCEPTED_SPEC_UNIQUE')
            ->assertSee('REJECTED_SPEC_UNIQUE')
            ->assertSee('EXPIRED_SPEC_UNIQUE');

        // Pending tab
        Livewire::test(CustomerRfqs::class)
            ->set('tab', 'pending')
            ->assertSee('PENDING_SPEC_UNIQUE')
            ->assertDontSee('QUOTED_SPEC_UNIQUE')
            ->assertDontSee('ACCEPTED_SPEC_UNIQUE')
            ->assertDontSee('REJECTED_SPEC_UNIQUE')
            ->assertDontSee('EXPIRED_SPEC_UNIQUE');

        // Quoted tab (active only)
        Livewire::test(CustomerRfqs::class)
            ->set('tab', 'quoted')
            ->assertSee('QUOTED_SPEC_UNIQUE')
            ->assertDontSee('PENDING_SPEC_UNIQUE')
            ->assertDontSee('ACCEPTED_SPEC_UNIQUE')
            ->assertDontSee('REJECTED_SPEC_UNIQUE')
            ->assertDontSee('EXPIRED_SPEC_UNIQUE');

        // Accepted tab
        Livewire::test(CustomerRfqs::class)
            ->set('tab', 'accepted')
            ->assertSee('ACCEPTED_SPEC_UNIQUE')
            ->assertDontSee('PENDING_SPEC_UNIQUE')
            ->assertDontSee('QUOTED_SPEC_UNIQUE')
            ->assertDontSee('REJECTED_SPEC_UNIQUE')
            ->assertDontSee('EXPIRED_SPEC_UNIQUE');

        // Declined tab (includes rejected and expired)
        Livewire::test(CustomerRfqs::class)
            ->set('tab', 'declined')
            ->assertSee('REJECTED_SPEC_UNIQUE')
            ->assertSee('EXPIRED_SPEC_UNIQUE')
            ->assertDontSee('PENDING_SPEC_UNIQUE')
            ->assertDontSee('QUOTED_SPEC_UNIQUE')
            ->assertDontSee('ACCEPTED_SPEC_UNIQUE');
    }

    public function test_buyer_can_confirm_and_submit_decline_quote_with_reason(): void
    {
        $rfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 250,
            'target_unit_price' => 90.00,
            'seller_quoted_price' => 120.00,
            'status' => 'quoted',
            'destination_district' => 'Dhaka',
            'valid_until' => now()->addDays(5),
        ]);

        $this->actingAs($this->buyer);

        Livewire::test(CustomerRfqs::class)
            ->call('confirmDecline', $rfq->id)
            ->assertSet('declineRfqId', $rfq->id)
            ->set('declineReason', 'Price is way above our maximum target budget.')
            ->call('submitDecline')
            ->assertSet('declineRfqId', null)
            ->assertSet('declineReason', '');

        $rfq->refresh();
        $this->assertSame('rejected', $rfq->status);
        $this->assertSame('buyer', $rfq->rejected_by);
        $this->assertSame('Price is way above our maximum target budget.', $rfq->rejection_reason);
    }

    public function test_buyer_cannot_decline_another_buyers_rfq(): void
    {
        $otherRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->otherBuyer->id,
            'buyer_name' => $this->otherBuyer->name,
            'buyer_email' => $this->otherBuyer->email,
            'buyer_phone' => $this->otherBuyer->phone,
            'target_quantity' => 100,
            'target_unit_price' => 80.00,
            'seller_quoted_price' => 85.00,
            'status' => 'quoted',
            'destination_district' => 'Dhaka',
            'valid_until' => now()->addDays(5),
        ]);

        $this->actingAs($this->buyer);

        Livewire::test(CustomerRfqs::class)
            ->call('confirmDecline', $otherRfq->id)
            ->assertSet('declineRfqId', null);

        $otherRfq->refresh();
        $this->assertSame('quoted', $otherRfq->status);
    }

    public function test_expired_quotes_show_expired_badge_and_cannot_be_accepted(): void
    {
        $expiredRfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 100,
            'target_unit_price' => 80.00,
            'seller_quoted_price' => 78.00,
            'status' => 'quoted',
            'destination_district' => 'Dhaka',
            'valid_until' => now()->subDay(),
        ]);

        $this->actingAs($this->buyer);

        Livewire::test(CustomerRfqs::class)
            ->assertSee('Expired')
            ->assertDontSee('Accept & Order');

        // Verify quotation sheet also displays expired notice
        $response = $this->get(route('rfq.quotation.print', $expiredRfq->id));
        $response->assertOk();
        $response->assertSee('Expired');
    }

    public function test_accepting_rfq_redirects_to_checkout_with_locked_quoted_rate(): void
    {
        $rfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => $this->buyer->phone,
            'target_quantity' => 200,
            'target_unit_price' => 80.00,
            'seller_quoted_price' => 75.50,
            'status' => 'quoted',
            'destination_district' => 'Dhaka',
            'valid_until' => now()->addDays(7),
        ]);

        $this->actingAs($this->buyer);

        $response = $this->post(route('rfq.quotation.accept', $rfq->id));

        $response->assertRedirect(route('checkout.index'));
        $this->assertSame('accepted', $rfq->fresh()->status);

        $cart = app(CartSessionService::class);
        $items = $cart->getItems();
        $this->assertNotEmpty($items);
        $cartItem = reset($items);
        $this->assertEquals(75.50, $cartItem['unit_price']);
        $this->assertSame(200, $cartItem['quantity']);
    }
}
