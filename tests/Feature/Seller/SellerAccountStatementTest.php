<?php

declare(strict_types=1);

namespace Tests\Feature\Seller;

use App\Filament\Seller\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Seller\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
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
}
