<?php

declare(strict_types=1);

namespace Tests\Feature\Monetization;

use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Monetization\SellerInvoiceSettlementService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MON-11: a seller on a limited plan can buy a listing pack (25 extra listings for 30 days, ৳250 by default) and pay
 * it like any other bill.
 */
class ListingPackPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Pack Store',
            'store_slug' => 'pack-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $this->plan = SellerPlan::create([
            'name' => 'Ten Listings',
            'slug' => 'ten-listings',
            'subscription_price' => 0,
            'max_active_products' => 10,
            'is_listing_limit_enabled' => true,
            'allow_listing_addons' => true,
            'is_active' => true,
        ]);
        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(90),
        ]);
        SellerWallet::create(['seller_id' => $this->seller->id, 'available_balance' => 1000, 'escrow_held_balance' => 0]);
    }

    private function settlement(): SellerInvoiceSettlementService
    {
        return app(SellerInvoiceSettlementService::class);
    }

    public function test_buying_and_paying_a_pack_adds_listings_and_books_revenue(): void
    {
        $engine = app(MonetizationEngineService::class);
        $this->assertSame(10, $engine->getAllowedProductCount($this->seller));

        $invoice = $this->settlement()->startListingPackPurchase($this->seller);

        $this->assertSame(SellerInvoice::TYPE_LISTING_PACK, $invoice->invoice_type);
        $this->assertSame(SellerInvoice::STATUS_UNPAID, $invoice->status);
        $this->assertEqualsWithDelta(250.0, (float) $invoice->total_amount, 0.001);
        $this->assertSame(25, $invoice->metadata['extra_slots']);
        $this->assertSame(10, $engine->getAllowedProductCount($this->seller), 'An unpaid pack adds nothing');

        $this->settlement()->payFromWallet($this->seller, $invoice->id);

        $this->assertSame(35, $engine->getAllowedProductCount($this->seller));
        $this->assertEqualsWithDelta(750.0, (float) SellerWallet::where('seller_id', $this->seller->id)->value('available_balance'), 0.001);
        $this->assertTrue(AccountingJournalEntry::where('financial_event', 'listing_pack_paid')->exists());

        $this->travel(31)->days();
        $this->assertSame(10, $engine->getAllowedProductCount($this->seller), 'A pack lasts 30 days');
    }

    public function test_a_second_click_returns_the_same_unpaid_bill(): void
    {
        $first = $this->settlement()->startListingPackPurchase($this->seller);
        $second = $this->settlement()->startListingPackPurchase($this->seller);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SellerInvoice::where('invoice_type', SellerInvoice::TYPE_LISTING_PACK)->count());
    }

    public function test_a_pack_is_refused_when_the_plan_has_no_limit_or_no_add_ons(): void
    {
        $this->plan->update(['allow_listing_addons' => false]);

        try {
            $this->settlement()->startListingPackPurchase($this->seller);
            $this->fail('A plan without add-ons must refuse a pack');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('does not offer listing packs', $exception->getMessage());
        }

        $this->plan->update(['allow_listing_addons' => true, 'is_listing_limit_enabled' => false]);
        SellerSubscription::where('seller_id', $this->seller->id)->update(['terms_captured_at' => null]);

        $this->expectExceptionMessage('no listing limit');
        $this->settlement()->startListingPackPurchase($this->seller->fresh());
    }

    public function test_seller_can_buy_a_pack_from_the_billing_page(): void
    {
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(SubscriptionBilling::class)
            ->assertSee('Buy 25 more listings')
            ->assertSee('৳250.00')
            ->call('buyListingPack')
            ->assertNotified();

        $this->assertSame(1, SellerInvoice::where('seller_id', $this->seller->id)->where('invoice_type', SellerInvoice::TYPE_LISTING_PACK)->count());
    }
}
