<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\OrderTracking;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicLookupSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private SubOrder $subOrder;

    private string $recipientPhone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->subOrder = SubOrder::with('order')->firstOrFail();
        $this->order = $this->subOrder->order;
        $this->recipientPhone = (string) $this->order->shipping_address['recipient_phone'];
    }

    private function makeCancellable(): void
    {
        $this->order->subOrders()->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id')]);
        $this->order->update(['order_status_id' => OrderStatus::where('slug', 'pending')->value('id')]);
    }

    private function makeDelivered(): void
    {
        $this->subOrder->update(['fulfillment_status_id' => FulfillmentStatus::where('slug', 'delivered')->value('id')]);
    }

    public function test_guest_cannot_view_invoice_by_order_number(): void
    {
        $this->get('/order/'.$this->order->order_number.'/invoice')
            ->assertRedirect(route('login'));
    }

    public function test_invoice_token_still_public(): void
    {
        $this->get('/invoice/'.$this->order->invoice_token)->assertOk();
    }

    public function test_api_login_is_rate_limited(): void
    {
        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $this->postJson('/api/v1/auth/login', ['email' => 'admin@b2bhaat.com', 'password' => 'wrong-'.$i]);
        }

        $last->assertStatus(429);
    }

    public function test_public_tracking_is_rate_limited(): void
    {
        $last = null;
        for ($i = 0; $i < 25; $i++) {
            $last = $this->getJson('/api/v1/orders/track?number=ORD-X-'.$i.'&phone=01711000000');
        }

        $last->assertStatus(429);
    }

    public function test_api_tracking_requires_exact_phone(): void
    {
        $this->getJson('/api/v1/orders/track?number='.$this->order->order_number)->assertStatus(422);
        $this->getJson('/api/v1/orders/track?number='.$this->order->order_number.'&phone='.urlencode($this->recipientPhone))->assertOk();
    }

    public function test_partial_phone_does_not_match(): void
    {
        $partial = substr(preg_replace('/\D/', '', $this->recipientPhone), -4);

        $this->getJson('/api/v1/orders/track?number='.$this->order->order_number.'&phone='.$partial)->assertNotFound();

        Livewire::test(OrderTracking::class, ['orderNumber' => $this->order->order_number, 'phone' => $partial])
            ->assertSet('order', null);
    }

    public function test_guest_cannot_cancel_order_with_only_order_number(): void
    {
        $this->makeCancellable();

        Livewire::test(OrderTracking::class, ['orderNumber' => $this->order->order_number])
            ->call('searchOrder')
            ->call('cancelEntireOrder');

        $this->assertNotSame('cancelled', $this->order->fresh()->orderStatus?->slug);
    }

    public function test_anonymous_guest_cannot_file_return_by_sub_order_id(): void
    {
        $this->makeDelivered();

        Livewire::test(OrderTracking::class, ['orderNumber' => 'NOT-A-REAL-ORDER'])
            ->set('selectedSubOrderId', $this->subOrder->id)
            ->set('returnReason', 'defective_item')
            ->set('returnRequestedAction', 'refund')
            ->set('returnDescription', 'Attacker filed this return anonymously.')
            ->call('submitReturnRequest');

        $this->assertDatabaseMissing('return_requests', ['sub_order_id' => $this->subOrder->id]);
    }

    public function test_owner_can_still_cancel_own_order(): void
    {
        $this->makeCancellable();
        $this->actingAs($this->order->customer);

        Livewire::test(OrderTracking::class, ['orderNumber' => $this->order->order_number])
            ->call('cancelEntireOrder');

        $this->assertSame('cancelled', $this->order->fresh()->orderStatus?->slug);
    }

    public function test_other_logged_in_user_cannot_cancel(): void
    {
        $this->makeCancellable();
        $this->actingAs(User::where('email', 'buyer@boutique.com')->firstOrFail());

        Livewire::test(OrderTracking::class, ['orderNumber' => $this->order->order_number])
            ->call('cancelEntireOrder');

        $this->assertNotSame('cancelled', $this->order->fresh()->orderStatus?->slug);
    }

    public function test_exact_phone_verified_guest_can_file_return(): void
    {
        $this->makeDelivered();

        Livewire::test(OrderTracking::class, ['orderNumber' => $this->order->order_number, 'phone' => $this->recipientPhone])
            ->call('openReturnModal', $this->subOrder->id)
            ->set('returnReason', 'defective_item')
            ->set('returnRequestedAction', 'refund')
            ->set('returnDescription', 'The gel polish had hardened upon receipt.')
            ->call('submitReturnRequest');

        $this->assertDatabaseHas('return_requests', ['sub_order_id' => $this->subOrder->id]);
    }
}
