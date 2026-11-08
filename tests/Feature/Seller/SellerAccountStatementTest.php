<?php

declare(strict_types=1);

namespace Tests\Feature\Seller;

use App\Filament\Seller\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Seller\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerAccountStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
    }

    public function test_seller_can_access_account_statement_page(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Statement Test Store',
            'store_slug' => 'statement-test-store',
            'is_approved' => true,
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $response = $this->get('/seller/account-statement');
        $response->assertSuccessful();
        $response->assertSee('Account Statement');
    }

    public function test_non_seller_cannot_access_seller_account_statement_page(): void
    {
        $customerRole = Role::where('slug', 'retail_buyer')->firstOrFail();
        $customer = User::factory()->create([
            'role_id' => $customerRole->id,
            'is_active' => true,
        ]);

        $this->actingAs($customer);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $response = $this->get('/seller/account-statement');
        $response->assertForbidden();
    }

    public function test_multi_tenant_isolation_seller_cannot_see_other_seller_transactions(): void
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();

        // Seller A
        $sellerA = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $sellerA->id,
            'store_name' => 'Seller Store A',
            'store_slug' => 'seller-store-a',
            'is_approved' => true,
        ]);
        $walletA = SellerWallet::create([
            'seller_id' => $sellerA->id,
            'available_balance' => 5000.00,
            'escrow_held_balance' => 0.00,
        ]);
        $txA = WalletTransaction::create([
            'seller_wallet_id' => $walletA->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'status' => WalletTransaction::STATUS_DISBURSED,
            'amount' => 5000.00,
            'running_balance' => 5000.00,
            'reference_note' => 'Statement Release For Seller A',
        ]);

        // Seller B
        $sellerB = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $sellerB->id,
            'store_name' => 'Seller Store B',
            'store_slug' => 'seller-store-b',
            'is_approved' => true,
        ]);
        $walletB = SellerWallet::create([
            'seller_id' => $sellerB->id,
            'available_balance' => 9000.00,
            'escrow_held_balance' => 0.00,
        ]);
        $txB = WalletTransaction::create([
            'seller_wallet_id' => $walletB->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'status' => WalletTransaction::STATUS_DISBURSED,
            'amount' => 9000.00,
            'running_balance' => 9000.00,
            'reference_note' => 'Confidential Note For Seller B',
        ]);

        // Authenticate as Seller A
        $this->actingAs($sellerA);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        // Query scope verification
        $scopedQuery = WalletTransactionResource::getEloquentQuery();
        $this->assertTrue($scopedQuery->where('id', $txA->id)->exists());
        $this->assertFalse($scopedQuery->where('id', $txB->id)->exists());

        // Livewire table isolation verification
        Livewire::test(ListWalletTransactions::class)
            ->assertCanSeeTableRecords([$txA])
            ->assertCanNotSeeTableRecords([$txB]);

        // Page HTTP response verification
        $response = $this->get('/seller/account-statement');
        $response->assertSuccessful();
        $response->assertSee('Statement Release For Seller A');
        $response->assertDontSee('Confidential Note For Seller B');
    }

    public function test_resource_disallows_creation_editing_and_deletion(): void
    {
        $tx = new WalletTransaction;

        $this->assertFalse(WalletTransactionResource::canCreate());
        $this->assertFalse(WalletTransactionResource::canEdit($tx));
        $this->assertFalse(WalletTransactionResource::canDelete($tx));
        $this->assertFalse(WalletTransactionResource::canDeleteAny());
    }

    public function test_statement_table_renders_columns_and_formatted_values(): void
    {
        [$seller, $wallet] = $this->createSellerWithWallet();
        $subOrder = $this->createSubOrder($seller, 'PKG-TEST-777');

        $txRelease = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'status' => null,
            'amount' => 1500.00,
            'running_balance' => 1500.00,
            'reference_note' => 'Released order funds',
            'created_at' => now(),
        ]);

        $txPayout = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => null,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 500.00,
            'running_balance' => 1000.00,
            'reference_note' => 'Bank withdrawal to City Bank',
            'created_at' => now()->subHour(),
        ]);

        $txReturn = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => null,
            'transaction_type' => WalletTransaction::TYPE_RETURN_REVERSAL,
            'status' => null,
            'amount' => 200.00,
            'running_balance' => 800.00,
            'reference_note' => 'Return deduction on returned item',
            'created_at' => now()->subHours(2),
        ]);

        $txReversal = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => null,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT_REVERSAL,
            'status' => null,
            'amount' => 500.00,
            'running_balance' => 1300.00,
            'reference_note' => 'Refunded payout to seller wallet',
            'created_at' => now()->subHours(3),
        ]);

        $txCod = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => null,
            'transaction_type' => WalletTransaction::TYPE_COD_SELF_COLLECTED,
            'status' => null,
            'amount' => 150.00,
            'running_balance' => 1150.00,
            'reference_note' => 'COD cash adjustment',
            'created_at' => now()->subHours(4),
        ]);

        $txRejectedPayout = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => null,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REJECTED,
            'amount' => 300.00,
            'running_balance' => 1150.00,
            'reference_note' => 'Rejected withdrawal',
            'created_at' => now()->subHours(5),
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListWalletTransactions::class)
            ->assertCanSeeTableRecords([$txRelease, $txPayout, $txReturn, $txReversal, $txCod, $txRejectedPayout])
            ->assertSee('Escrow Released')
            ->assertSee('Payout Withdrawal')
            ->assertSee('Return / Refund Reversal')
            ->assertSee('Payout Refunded')
            ->assertSee('COD Adjustment')
            ->assertSee('+৳1,500.00')
            ->assertSee('-৳500.00')
            ->assertSee('-৳200.00')
            ->assertSee('+৳500.00')
            ->assertSee('-৳150.00')
            ->assertSee('৳1,500.00')
            ->assertSee('৳1,000.00')
            ->assertSee('৳800.00')
            ->assertSee('৳1,300.00')
            ->assertSee('৳1,150.00')
            ->assertSee('Waiting to be paid')
            ->assertSee('Rejected')
            ->assertSee('Package #PKG-TEST-777');
    }

    public function test_statement_table_filters_by_date_range(): void
    {
        [$seller, $wallet] = $this->createSellerWithWallet();

        $txOld = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 100.00,
            'running_balance' => 100.00,
            'reference_note' => 'Old Transaction 10 Days Ago',
        ]);
        $txOld->timestamps = false;
        $txOld->created_at = now()->subDays(10);
        $txOld->save();

        $txTarget = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 200.00,
            'running_balance' => 300.00,
            'reference_note' => 'Target Transaction 3 Days Ago',
        ]);
        $txTarget->timestamps = false;
        $txTarget->created_at = now()->subDays(3);
        $txTarget->save();

        $txRecent = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 300.00,
            'running_balance' => 600.00,
            'reference_note' => 'Recent Transaction Today',
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListWalletTransactions::class)
            ->assertCanSeeTableRecords([$txOld, $txTarget, $txRecent])
            ->filterTable('created_at', [
                'from' => now()->subDays(5)->toDateString(),
                'until' => now()->subDays(1)->toDateString(),
            ])
            ->assertCanSeeTableRecords([$txTarget])
            ->assertCanNotSeeTableRecords([$txOld, $txRecent]);
    }

    public function test_statement_table_filters_by_transaction_type(): void
    {
        [$seller, $wallet] = $this->createSellerWithWallet();

        $txRelease = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 1000.00,
            'running_balance' => 1000.00,
            'reference_note' => 'Release Transaction',
        ]);

        $txPayout = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'amount' => 400.00,
            'running_balance' => 600.00,
            'reference_note' => 'Payout Transaction',
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListWalletTransactions::class)
            ->filterTable('transaction_type', WalletTransaction::TYPE_ESCROW_RELEASE)
            ->assertCanSeeTableRecords([$txRelease])
            ->assertCanNotSeeTableRecords([$txPayout]);
    }

    public function test_statement_table_filters_by_payout_status(): void
    {
        [$seller, $wallet] = $this->createSellerWithWallet();

        $txPending = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 500.00,
            'running_balance' => 500.00,
            'reference_note' => 'Pending Payout',
        ]);

        $txPaid = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_DISBURSED,
            'amount' => 300.00,
            'running_balance' => 200.00,
            'reference_note' => 'Completed Payout',
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListWalletTransactions::class)
            ->filterTable('status', WalletTransaction::STATUS_REQUESTED)
            ->assertCanSeeTableRecords([$txPending])
            ->assertCanNotSeeTableRecords([$txPaid]);
    }

    public function test_statement_table_searches_by_reference_note_and_order_number(): void
    {
        [$seller, $wallet] = $this->createSellerWithWallet();
        $subOrder = $this->createSubOrder($seller, 'PKG-UNIQUE-999');

        $txWithNote = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 100.00,
            'running_balance' => 100.00,
            'reference_note' => 'Special Unique Custom Memo Note',
        ]);

        $txWithSubOrder = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'sub_order_id' => $subOrder->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 200.00,
            'running_balance' => 300.00,
            'reference_note' => 'Standard settlement',
        ]);

        $txOther = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 50.00,
            'running_balance' => 350.00,
            'reference_note' => 'Unrelated other record',
        ]);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        // Search reference note
        Livewire::test(ListWalletTransactions::class)
            ->searchTable('Special Unique Custom Memo')
            ->assertCanSeeTableRecords([$txWithNote])
            ->assertCanNotSeeTableRecords([$txWithSubOrder, $txOther]);

        // Search sub-order package number
        Livewire::test(ListWalletTransactions::class)
            ->searchTable('PKG-UNIQUE-999')
            ->assertCanSeeTableRecords([$txWithSubOrder])
            ->assertCanNotSeeTableRecords([$txWithNote, $txOther]);
    }

    public function test_statement_table_displays_empty_state_when_no_records_exist(): void
    {
        [$seller] = $this->createSellerWithWallet();

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListWalletTransactions::class)
            ->assertSee('No transactions recorded yet')
            ->assertSee('All escrow releases, commission withholdings, and payout withdrawals will appear here automatically.');
    }

    /**
     * @return array{0: User, 1: SellerWallet, 2: SellerProfile}
     */
    protected function createSellerWithWallet(float $available = 10000.00, float $held = 0.00): array
    {
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Statement Test Store '.$seller->id,
            'store_slug' => 'statement-test-store-'.$seller->id,
            'is_approved' => true,
        ]);

        $wallet = SellerWallet::create([
            'seller_id' => $seller->id,
            'available_balance' => $available,
            'escrow_held_balance' => $held,
        ]);

        return [$seller, $wallet, $profile];
    }

    protected function createSubOrder(User $seller, string $subOrderNumber = 'PKG-TEST-001'): SubOrder
    {
        $orderStatus = OrderStatus::first() ?? OrderStatus::create(['name' => 'Pending', 'slug' => 'pending']);
        $paymentStatus = PaymentStatus::first() ?? PaymentStatus::create(['name' => 'Paid', 'slug' => 'paid']);
        $paymentMethod = PaymentMethod::first() ?? PaymentMethod::create(['name' => 'bKash', 'slug' => 'bkash']);
        $fulfillmentStatus = FulfillmentStatus::first() ?? FulfillmentStatus::create(['name' => 'Delivered', 'slug' => 'delivered']);

        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'customer_id' => $seller->id,
            'order_status_id' => $orderStatus->id,
            'payment_status_id' => $paymentStatus->id,
            'payment_method_id' => $paymentMethod->id,
            'shipping_address' => ['city' => 'Dhaka'],
            'total_amount' => 1000.00,
        ]);

        return SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => $subOrderNumber,
            'fulfillment_status_id' => $fulfillmentStatus->id,
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
        ]);
    }
}
