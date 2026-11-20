<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\SellerWallets\Pages\ListSellerWallets;
use App\Models\SellerWallet;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEC-1: a seller must never be able to create or change a wallet balance.
 */
class SellerWalletLockdownTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);
    }

    public function test_wallet_list_offers_no_create_button(): void
    {
        Livewire::test(ListSellerWallets::class)
            ->assertSuccessful()
            ->assertActionDoesNotExist(CreateAction::class);
    }

    public function test_policy_denies_seller_wallet_writes(): void
    {
        $wallet = SellerWallet::firstOrCreate(['seller_id' => $this->seller->id]);

        $this->assertTrue($this->seller->can('view', $wallet));
        $this->assertFalse($this->seller->can('create', SellerWallet::class));
        $this->assertFalse($this->seller->can('update', $wallet));
        $this->assertFalse($this->seller->can('delete', $wallet));
    }

    public function test_seller_cannot_view_another_sellers_wallet(): void
    {
        $otherSeller = User::factory()->create(['role_id' => $this->seller->role_id]);
        $otherWallet = SellerWallet::firstOrCreate(['seller_id' => $otherSeller->id]);

        $this->assertFalse($this->seller->can('view', $otherWallet));
    }
}
