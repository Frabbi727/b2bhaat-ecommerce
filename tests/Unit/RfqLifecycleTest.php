<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RfqLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_rfq_model_casts_attributes_correctly(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'target_quantity' => 100,
            'valid_until' => '2026-12-01 12:00:00',
            'dispatch_days' => '7',
            'status' => 'quoted',
        ]);

        $this->assertInstanceOf(\DateTimeInterface::class, $rfq->valid_until);
        $this->assertIsInt($rfq->dispatch_days);
        $this->assertSame(7, $rfq->dispatch_days);
    }

    public function test_is_expired_returns_true_when_valid_until_is_in_the_past(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfq = new RfqRequest([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'target_quantity' => 50,
            'valid_until' => Carbon::now()->subMinute(),
        ]);

        $this->assertTrue($rfq->isExpired());
    }

    public function test_is_expired_returns_false_when_valid_until_is_in_the_future_or_null(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfqFuture = new RfqRequest([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'target_quantity' => 50,
            'valid_until' => Carbon::now()->addDay(),
        ]);
        $this->assertFalse($rfqFuture->isExpired());

        $rfqNull = new RfqRequest([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'target_quantity' => 50,
            'valid_until' => null,
        ]);
        $this->assertFalse($rfqNull->isExpired());
    }

    public function test_can_be_accepted_returns_true_only_when_quoted_and_not_expired(): void
    {
        $rfq = new RfqRequest([
            'status' => 'quoted',
            'valid_until' => Carbon::now()->addDays(3),
        ]);
        $this->assertTrue($rfq->canBeAccepted());

        $rfqNoExpiry = new RfqRequest([
            'status' => 'quoted',
            'valid_until' => null,
        ]);
        $this->assertTrue($rfqNoExpiry->canBeAccepted());

        $rfqExpired = new RfqRequest([
            'status' => 'quoted',
            'valid_until' => Carbon::now()->subHour(),
        ]);
        $this->assertFalse($rfqExpired->canBeAccepted());

        $rfqPending = new RfqRequest([
            'status' => 'pending',
            'valid_until' => Carbon::now()->addDays(3),
        ]);
        $this->assertFalse($rfqPending->canBeAccepted());

        $rfqAlreadyAccepted = new RfqRequest([
            'status' => 'accepted',
            'valid_until' => Carbon::now()->addDays(3),
        ]);
        $this->assertFalse($rfqAlreadyAccepted->canBeAccepted());
    }

    public function test_accept_and_add_to_cart_rejects_expired_quotation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'target_quantity' => 100,
            'target_unit_price' => 100.00,
            'seller_quoted_price' => 90.00,
            'status' => 'quoted',
            'valid_until' => Carbon::now()->subMinute(),
        ]);

        $this->actingAs($buyer);

        $response = $this->from('/rfq/'.$rfq->id.'/quotation')
            ->post(route('rfq.quotation.accept', $rfq->id));

        $response->assertRedirect('/rfq/'.$rfq->id.'/quotation');
        $response->assertSessionHas('error', 'This quotation has expired and can no longer be accepted.');

        $this->assertSame('quoted', $rfq->fresh()->status);
    }

    public function test_buyer_can_decline_open_quotation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'target_quantity' => 100,
            'target_unit_price' => 100.00,
            'seller_quoted_price' => 95.00,
            'status' => 'quoted',
        ]);

        $this->actingAs($buyer);

        $response = $this->from('/rfq/'.$rfq->id.'/quotation')
            ->post(route('rfq.quotation.decline', $rfq->id), [
                'rejection_reason' => 'Target price exceeded budget.',
            ]);

        $response->assertRedirect('/rfq/'.$rfq->id.'/quotation');
        $response->assertSessionHas('success', "Quotation #QT-RFQ-{$rfq->id} has been declined.");

        $rfq->refresh();
        $this->assertSame('rejected', $rfq->status);
        $this->assertSame('buyer', $rfq->rejected_by);
        $this->assertSame('Target price exceeded budget.', $rfq->rejection_reason);
    }

    public function test_unauthorized_user_cannot_decline_quotation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $intruder = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'target_quantity' => 100,
            'status' => 'quoted',
        ]);

        $this->actingAs($intruder);

        $response = $this->post(route('rfq.quotation.decline', $rfq->id), [
            'rejection_reason' => 'Malicious decline',
        ]);

        $response->assertForbidden();
        $this->assertSame('quoted', $rfq->fresh()->status);
    }

    public function test_super_admin_can_decline_quotation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'target_quantity' => 100,
            'status' => 'quoted',
        ]);

        $this->actingAs($admin);

        $response = $this->from('/rfq/'.$rfq->id.'/quotation')
            ->post(route('rfq.quotation.decline', $rfq->id));

        $response->assertRedirect('/rfq/'.$rfq->id.'/quotation');
        $this->assertSame('rejected', $rfq->fresh()->status);
        $this->assertSame('buyer', $rfq->fresh()->rejected_by);
    }

    public function test_cannot_decline_already_accepted_quotation(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::firstOrFail();

        $rfq = RfqRequest::create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'buyer_email' => $buyer->email,
            'buyer_phone' => '01711223344',
            'target_quantity' => 100,
            'status' => 'accepted',
        ]);

        $this->actingAs($buyer);

        $response = $this->from('/rfq/'.$rfq->id.'/quotation')
            ->post(route('rfq.quotation.decline', $rfq->id));

        $response->assertRedirect('/rfq/'.$rfq->id.'/quotation');
        $response->assertSessionHas('error');
        $this->assertSame('accepted', $rfq->fresh()->status);
    }
}
