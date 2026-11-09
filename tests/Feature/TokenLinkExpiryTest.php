<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Storefront\OrderTracking;
use App\Models\Order;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEC-8: the secret tracking and invoice links sent by SMS stop working after a set time, so an old message
 * can't be used forever to see the address, handover code or invoice.
 */
class TokenLinkExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->order = Order::firstOrFail();
        config(['orders.tracking_link_days' => 90, 'orders.invoice_link_days' => 365]);
    }

    private function ageOrder(int $days): void
    {
        $this->order->forceFill(['created_at' => now()->subDays($days)])->save();
    }

    public function test_links_work_inside_their_window(): void
    {
        $this->ageOrder(89);

        $this->get('/invoice/'.$this->order->invoice_token)->assertOk();
        Livewire::test(OrderTracking::class, ['token' => $this->order->tracking_token])
            ->assertSet('verifiedAccess', true);
    }

    public function test_an_old_tracking_link_no_longer_opens_the_order(): void
    {
        $this->ageOrder(91);

        Livewire::test(OrderTracking::class, ['token' => $this->order->tracking_token])
            ->assertSet('verifiedAccess', false)
            ->assertSet('order', null)
            ->assertSee('This tracking link has expired');
    }

    public function test_an_old_invoice_link_is_gone(): void
    {
        $this->ageOrder(366);

        $this->get('/invoice/'.$this->order->invoice_token)->assertStatus(410);
        // The signed-in buyer can still open it from their orders
        $this->actingAs($this->order->customer)->get(route('order.invoice', $this->order->order_number))->assertOk();
    }
}
