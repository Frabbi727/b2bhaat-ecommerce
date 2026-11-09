<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-9: a seller opening a shared order's invoice sees only their own packages and totals, never another
 * seller's products, prices or the whole order total.
 */
class SellerInvoiceScopeTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private SubOrder $mine;

    private SubOrder $theirs;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $other = User::where('email', 'haier.official@b2bhaat.com')->firstOrFail();

        $this->order = Order::has('subOrders')->firstOrFail();
        $this->mine = $this->order->subOrders()->firstOrFail();
        $this->mine->update(['seller_id' => $this->seller->id, 'subtotal' => 1111.00, 'shipping_amount' => 60.00]);

        $this->theirs = $this->mine->replicate();
        $this->theirs->forceFill([
            'seller_id' => $other->id,
            'sub_order_number' => 'SO-OTHER-SELLER-9',
            'subtotal' => 7777.00,
        ])->save();
        $this->order->update(['total_amount' => 8948.00]);
    }

    public function test_a_seller_sees_only_their_own_package(): void
    {
        $this->actingAs($this->seller)
            ->get(route('order.invoice', $this->order->order_number))
            ->assertSuccessful()
            ->assertSee($this->mine->sub_order_number)
            ->assertDontSee('SO-OTHER-SELLER-9')
            ->assertDontSee('8,948.00')
            ->assertSee('1,171.00');
    }

    public function test_the_buyer_and_admin_still_see_the_whole_order(): void
    {
        foreach ([$this->order->customer, User::where('email', 'admin@b2bhaat.com')->firstOrFail()] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('order.invoice', $this->order->order_number))
                ->assertSuccessful()
                ->assertSee('SO-OTHER-SELLER-9')
                ->assertSee('8,948.00');
        }
    }
}
