<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Livewire\Storefront\OrderTracking;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\SelfDeliveryService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Final review C1 + C2: a seller must not be able to confirm their own self-delivery, and a self-delivered COD
 * order must not credit the seller's wallet on top of the cash they collected.
 */
class SelfDeliveryFraudTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private SubOrder $selfDelivered;

    private User $sellerA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->order = MixedOrderFixture::create('cod', 'unpaid');
        $this->order->update(['tracking_token' => 'tok-'.str_repeat('a', 30)]);
        $this->selfDelivered = $this->order->subOrders->firstWhere('subtotal', 1000);
        $this->sellerA = User::findOrFail($this->selfDelivered->seller_id);
    }

    public function test_seller_dispatch_notification_does_not_reveal_handover_code(): void
    {
        $this->selfDelivered->update([
            'is_self_delivered' => false,
            'handover_code' => null,
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'unfulfilled')->value('id'),
        ]);

        $this->actingAs($this->sellerA);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListSubOrders::class)
            ->callAction(TestAction::make('dispatchFactoryFleet')->table($this->selfDelivered), [
                'hub_location' => 'Factory Bay', 'driver_contact' => '01700000000', 'dispatch_note' => 'Truck 4',
            ])
            ->assertHasNoActionErrors();

        $code = (string) $this->selfDelivered->fresh()->handover_code;
        $this->assertSame(6, strlen($code));

        // Read exactly what the seller was shown (same mechanism Filament's assertNotified uses)
        app(NotificationMailbox::class)->flush();
        $shown = new Notifications;
        $shown->mount();
        $shown->pullNotificationsFromSession();
        $text = $shown->notifications->map(fn ($notification) => $notification->getTitle().' '.$notification->getBody())->implode(' ');

        $this->assertStringContainsString('Dispatched via Factory Direct Fleet', $text, 'probe: the dispatch notification must be captured');
        $this->assertStringNotContainsString($code, $text);
    }

    public function test_phone_lookup_does_not_show_handover_code(): void
    {
        Livewire::test(OrderTracking::class)
            ->set('orderNumber', $this->order->order_number)
            ->set('phone', '01711000000')
            ->call('searchOrder')
            ->assertSet('searchError', '')
            ->assertSee($this->order->order_number)
            ->assertDontSee('Your delivery code');
    }

    public function test_tracking_token_holder_sees_handover_code(): void
    {
        Livewire::test(OrderTracking::class, ['token' => $this->order->tracking_token])
            ->assertSee('Your delivery code')
            ->assertSee('4321');
    }

    public function test_signed_in_owner_sees_handover_code(): void
    {
        $this->actingAs(User::findOrFail($this->order->customer_id));

        Livewire::test(OrderTracking::class)
            ->set('orderNumber', $this->order->order_number)
            ->call('searchOrder')
            ->assertSee('Your delivery code');
    }

    public function test_handover_code_is_locked_after_five_wrong_attempts(): void
    {
        $service = app(SelfDeliveryService::class);

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->verifyAndConfirmHandover($this->selfDelivered, '000000');
            } catch (Exception) {
                // wrong code
            }
        }

        try {
            $service->verifyAndConfirmHandover($this->selfDelivered, '4321');
            $this->fail('The correct code must be refused once attempts are exhausted');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Too many', $exception->getMessage());
        }

        $this->assertSame('in_transit', $this->selfDelivered->fresh()->fulfillmentStatus->slug);
    }

    public function test_self_delivered_cod_charges_commission_and_credits_nothing(): void
    {
        // Start from an empty wallet so "cannot withdraw" is meaningful (demo seed funds seller A)
        SellerWallet::where('seller_id', $this->sellerA->id)->update(['available_balance' => 0, 'escrow_held_balance' => 0]);

        app(SelfDeliveryService::class)->verifyAndConfirmHandover($this->selfDelivered, '4321');

        // Seller collected 1000 + 70 − 33.33 = 1036.67 cash; is owed 900 net + 70 fee = 970 → owes the platform 66.67
        $wallet = SellerWallet::where('seller_id', $this->sellerA->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->escrow_held_balance, 0.001, 'No escrow hold: the platform never received this cash');
        $this->assertEqualsWithDelta(-66.67, (float) $wallet->available_balance, 0.001);
        $this->assertDatabaseHas('wallet_transactions', [
            'sub_order_id' => $this->selfDelivered->id,
            'transaction_type' => WalletTransaction::TYPE_COD_SELF_COLLECTED,
            'amount' => 66.67,
        ]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'sub_order_id' => $this->selfDelivered->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_HOLD,
        ]);

        // The 48 h release adds nothing, and the seller cannot withdraw
        $this->travel(49)->hours();
        $this->artisan('escrow:release-delivered')->assertSuccessful();
        $this->assertEqualsWithDelta(-66.67, (float) $wallet->fresh()->available_balance, 0.001);

        $this->expectException(Exception::class);
        app(EscrowLedgerService::class)->requestPayout($this->sellerA, 500.0);
    }

    public function test_return_of_self_delivered_cod_reverses_the_commission_charge(): void
    {
        SellerWallet::where('seller_id', $this->sellerA->id)->update(['available_balance' => 0, 'escrow_held_balance' => 0]);

        app(SelfDeliveryService::class)->verifyAndConfirmHandover($this->selfDelivered, '4321');

        app(EscrowLedgerService::class)->reverseReturnedSubOrder($this->selfDelivered->fresh(), 'Returned');
        app(EscrowLedgerService::class)->reverseReturnedSubOrder($this->selfDelivered->fresh(), 'Returned again');

        $wallet = SellerWallet::where('seller_id', $this->sellerA->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->available_balance, 0.001);
        $this->assertSame(1, WalletTransaction::where('sub_order_id', $this->selfDelivered->id)
            ->where('transaction_type', WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL)->count());
    }

    public function test_courier_delivered_cod_is_still_held_then_released(): void
    {
        $courier = $this->order->subOrders->firstWhere('subtotal', 500);
        $this->signedCourierPost(['tracking_number' => $courier->tracking_number, 'status' => 'delivered'])->assertOk();

        $this->assertDatabaseHas('wallet_transactions', [
            'sub_order_id' => $courier->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_HOLD,
            'amount' => 450.00,
        ]);
    }
}
