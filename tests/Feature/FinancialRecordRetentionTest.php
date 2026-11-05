<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Order;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRA-044 / PRA-049: orders, users and ledgers are never hard-deleted; orders are only created by checkout.
 */
class FinancialRecordRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_cannot_delete_order(): void
    {
        $order = Order::firstOrFail();

        Livewire::test(ListOrders::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($order))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionDoesNotExist('delete');

        $this->assertFalse($this->admin->can('delete', $order));
        $this->assertFalse($this->admin->can('forceDelete', $order));
    }

    public function test_admin_order_create_route_absent(): void
    {
        $this->get('/admin/orders/create')->assertNotFound();
        $this->assertFalse($this->admin->can('create', Order::class));
    }

    public function test_admin_cannot_change_order_money_or_payment_fields(): void
    {
        $order = Order::firstOrFail();
        $originalTotal = (float) $order->total_amount;
        $originalPaymentStatus = $order->payment_status_id;

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertFormFieldIsDisabled('total_amount')
            ->assertFormFieldIsDisabled('payment_status_id')
            ->assertFormFieldIsDisabled('customer_id')
            ->fillForm(['total_amount' => 1.00])
            ->call('save');

        $this->assertEquals($originalTotal, (float) $order->fresh()->total_amount);
        $this->assertSame($originalPaymentStatus, $order->fresh()->payment_status_id);
    }

    public function test_deleting_seller_with_wallet_is_refused(): void
    {
        // A seller whose only dependent row is the wallet, so the wallet FK alone must stop the delete
        $seller = User::create([
            'role_id' => Role::where('slug', 'seller')->value('id'),
            'name' => 'Wallet Only Seller',
            'email' => 'wallet-only@factory.test',
            'password' => bcrypt(Str::random(32)),
            'is_active' => true,
        ]);
        SellerWallet::create(['seller_id' => $seller->id, 'available_balance' => 0, 'escrow_held_balance' => 0]);

        $this->expectException(QueryException::class);
        // Bypass Eloquent: the database itself must refuse to drop the ledger owner
        DB::table('users')->where('id', $seller->id)->delete();
    }

    public function test_admin_can_deactivate_user(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        Livewire::test(ListUsers::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->callAction(TestAction::make('deactivate')->table($customer))
            ->assertHasNoActionErrors();

        $this->assertFalse($customer->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $customer->id]);

        Livewire::test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->assertActionDoesNotExist('delete');
        $this->assertFalse($this->admin->can('delete', $customer));
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('deactivate')->table($this->admin));
    }
}
