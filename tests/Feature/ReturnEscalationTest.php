<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\ReturnRequests\Pages\ListReturnRequests;
use App\Livewire\Storefront\OrderTracking;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\ReturnRequest;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\Orders\ReturnEscalated;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Orders\OrderDisputeService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roadmap 3.4 (WA-ORD-02): a buyer may ask B2Bhaat to review a return the seller rejected, once, within the
 * window. The seller's escrow stays on hold while the buyer can still ask and while an admin decides.
 */
class ReturnEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
            'is_active' => true,
        ]);
    }

    /**
     * A prepaid parcel delivered $hoursAgo, its money held, and a return the seller rejected $rejectedHoursAgo.
     */
    private function rejectedReturn(int $deliveredHoursAgo = 49, int $rejectedHoursAgo = 1, string $wants = 'replacement'): ReturnRequest
    {
        $number = 'ORD-ESC-'.uniqid();
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => $this->customer->id,
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'paid')->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', 'mock_gateway')->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000'],
            'total_amount' => 1000.00,
        ]);
        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id'),
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
        ]);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);
        OrderTrackingLog::create([
            'sub_order_id' => $subOrder->id,
            'fulfillment_status_id' => $subOrder->fulfillment_status_id,
            'title' => 'Delivered', 'description' => 'test', 'hub_location' => 'x',
            'logged_at' => now()->subHours($deliveredHoursAgo),
        ]);

        return ReturnRequest::create([
            'sub_order_id' => $subOrder->id,
            'customer_id' => $this->customer->id,
            'reason' => 'damaged_goods',
            'requested_action' => $wants,
            'description' => 'Arrived broken',
            'status' => ReturnRequest::STATUS_REJECTED,
            'seller_note' => 'It was fine when packed',
            'resolved_at' => now()->subHours($rejectedHoursAgo),
        ]);
    }

    private function released(ReturnRequest $returnRequest): bool
    {
        return WalletTransaction::where('sub_order_id', $returnRequest->sub_order_id)
            ->where('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
            ->exists();
    }

    public function test_escrow_waits_while_the_buyer_can_still_escalate(): void
    {
        $returnRequest = $this->rejectedReturn(deliveredHoursAgo: 60, rejectedHoursAgo: 1);

        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertFalse($this->released($returnRequest));

        // Once the window after the rejection has passed, the money is released as usual
        $returnRequest->update(['resolved_at' => now()->subHours(49)]);
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertTrue($this->released($returnRequest));
    }

    public function test_escalating_holds_the_money_and_tells_the_admins(): void
    {
        Notification::fake();
        $returnRequest = $this->rejectedReturn();

        app(OrderDisputeService::class)->escalateReturnRequest($returnRequest, $this->customer, 'The box was crushed, I have photos.');

        $returnRequest->refresh();
        $this->assertSame(ReturnRequest::STATUS_ESCALATED, $returnRequest->status);
        Notification::assertSentTo($this->admin, ReturnEscalated::class);

        // Even long after the window, an escalated return keeps the money on hold
        $returnRequest->update(['resolved_at' => now()->subHours(100)]);
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertFalse($this->released($returnRequest));
    }

    public function test_a_return_can_be_escalated_only_once_and_only_by_its_buyer(): void
    {
        Notification::fake();
        $returnRequest = $this->rejectedReturn();

        try {
            app(OrderDisputeService::class)->escalateReturnRequest($returnRequest, $this->seller, 'Not my order but trying.');
            $this->fail('Someone else escalated the return.');
        } catch (Exception) {
        }

        app(OrderDisputeService::class)->escalateReturnRequest($returnRequest, $this->customer, 'The box was crushed, I have photos.');
        app(OrderDisputeService::class)->decideEscalatedReturn($returnRequest, false, 'Photos show no damage.');

        $this->expectExceptionMessage('already been asked');
        app(OrderDisputeService::class)->escalateReturnRequest($returnRequest->fresh(), $this->customer, 'Asking again please review.');
    }

    public function test_the_window_closes(): void
    {
        $returnRequest = $this->rejectedReturn(rejectedHoursAgo: 49);

        $this->expectExceptionMessage('can no longer be sent');
        app(OrderDisputeService::class)->escalateReturnRequest($returnRequest, $this->customer, 'The box was crushed, I have photos.');
    }

    public function test_keeping_the_rejection_lets_the_money_go_to_the_seller(): void
    {
        Notification::fake();
        $returnRequest = $this->rejectedReturn();
        app(OrderDisputeService::class)->escalateReturnRequest($returnRequest, $this->customer, 'The box was crushed, I have photos.');

        app(OrderDisputeService::class)->decideEscalatedReturn($returnRequest, false, 'Photos show no damage.');

        $returnRequest->refresh();
        $this->assertSame(ReturnRequest::STATUS_REJECTED, $returnRequest->status);
        $this->assertSame('It was fine when packed', $returnRequest->seller_note);
        $this->assertSame('Photos show no damage.', $returnRequest->admin_arbitration_note);
        $this->assertFalse($returnRequest->canBeEscalated());

        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertTrue($this->released($returnRequest));
    }

    public function test_approving_runs_the_normal_approval_and_keeps_the_sellers_note(): void
    {
        Notification::fake();
        $returnRequest = $this->rejectedReturn(wants: 'refund');
        app(OrderDisputeService::class)->escalateReturnRequest($returnRequest, $this->customer, 'The box was crushed, I have photos.');

        app(OrderDisputeService::class)->decideEscalatedReturn($returnRequest, true, 'Photos show damage in transit.');

        $returnRequest->refresh();
        $this->assertSame(ReturnRequest::STATUS_APPROVED, $returnRequest->status);
        $this->assertSame('It was fine when packed', $returnRequest->seller_note);
        $this->assertNotNull($returnRequest->escalation_decided_at);
        // The whole parcel is refunded: it is marked returned and the seller's held money goes back
        $this->assertSame('returned', $returnRequest->subOrder->fresh()->fulfillmentStatus->slug);
        $this->assertTrue(WalletTransaction::where('sub_order_id', $returnRequest->sub_order_id)->where('transaction_type', WalletTransaction::TYPE_ESCROW_REFUND)->exists());
    }

    public function test_the_seller_cannot_resolve_an_escalated_return(): void
    {
        Notification::fake();
        $returnRequest = $this->rejectedReturn();
        app(OrderDisputeService::class)->escalateReturnRequest($returnRequest, $this->customer, 'The box was crushed, I have photos.');

        app(OrderDisputeService::class)->resolveReturnRequest($returnRequest, ReturnRequest::STATUS_APPROVED, 'seller tries');

        $this->assertSame(ReturnRequest::STATUS_ESCALATED, $returnRequest->fresh()->status);
    }

    public function test_the_buyer_escalates_from_the_order_page_and_the_admin_decides(): void
    {
        Notification::fake();
        $returnRequest = $this->rejectedReturn();
        $order = $returnRequest->subOrder->order;

        $this->actingAs($this->customer);
        Livewire::test(OrderTracking::class)
            ->set('orderNumber', $order->order_number)
            ->call('searchOrder')
            ->assertSee('Ask B2Bhaat to review')
            ->call('openEscalation', $returnRequest->id)
            ->set('escalationNote', 'The box was crushed, I have photos.')
            ->call('submitEscalation')
            ->assertHasNoErrors()
            ->assertSee('B2Bhaat is reviewing your return');

        $this->assertSame(ReturnRequest::STATUS_ESCALATED, $returnRequest->fresh()->status);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListReturnRequests::class)
            ->assertSee('Buyer asks you to decide')
            ->callTableAction('keepRejection', $returnRequest, data: ['note' => 'Photos show no damage.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ReturnRequest::STATUS_REJECTED, $returnRequest->fresh()->status);
    }
}
