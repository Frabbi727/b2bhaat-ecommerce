<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\StoreSettings;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Monetization\SellerInvoiceSettlementService;
use App\Services\Monetization\SellerTermsSummary;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decisions of 2026-11-09:
 * - MON-8: the marketplace fees switch keeps its meaning (off = no commission, bills or limits) and admins can see
 *   and change it, with that explained.
 * - MON-15: a plan with "Charge Subscription Fee" off is never billed.
 * - MON-16: there are no per-category commission rates.
 */
class MonetizationOwnerDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
    }

    private function feeFreePlan(): SellerPlan
    {
        return SellerPlan::create([
            'name' => 'Starter', 'slug' => 'starter-mon15',
            'is_subscription_fee_enabled' => false, 'subscription_price' => 499.00, 'billing_cycle_days' => 30,
            'is_listing_limit_enabled' => false, 'is_commission_enabled' => true,
            'retail_commission_rate' => 8.00, 'wholesale_commission_rate' => 4.00, 'is_active' => true,
        ]);
    }

    public function test_changing_to_a_plan_without_a_fee_raises_no_bill(): void
    {
        $invoice = app(SellerInvoiceSettlementService::class)->startPlanChange($this->seller, $this->feeFreePlan()->id);

        $this->assertSame(0.0, (float) $invoice->total_amount);
        $this->assertSame(SellerInvoice::STATUS_PAID, $invoice->status);
        $this->assertSame(SellerSubscription::STATUS_ACTIVE, SellerSubscription::findOrFail($invoice->subscription_id)->status);
    }

    public function test_a_plan_without_a_fee_renews_free(): void
    {
        $subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id, 'plan_id' => $this->feeFreePlan()->id, 'status' => SellerSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(29), 'ends_at' => now()->addDay(), 'auto_renew' => true,
        ]);
        $endsAt = $subscription->ends_at;

        $this->artisan('monetization:process-renewals')->assertSuccessful();

        $this->assertSame(0, SellerInvoice::where('seller_id', $this->seller->id)->where('status', SellerInvoice::STATUS_UNPAID)->count());
        $this->assertTrue($subscription->fresh()->ends_at->gt($endsAt));
        $this->assertNull(app(SellerTermsSummary::class)->forSeller($this->seller->fresh())['next_payment_amount']);
    }

    public function test_a_category_never_sets_the_commission(): void
    {
        $product = Product::where('seller_id', $this->seller->id)->with('category')->firstOrFail();
        $product->setRelation('category', (new Category)->forceFill(['name' => 'Any', 'commission_rate_percentage' => 50.00]));
        $engine = app(MonetizationEngineService::class);

        $charged = $engine->resolveCommissionRate($this->seller, $product, SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail());

        $this->assertSame($engine->sellerCommissionRate($this->seller, wholesale: false), $charged);
        $this->assertNotSame(50.0, $charged);
    }

    public function test_admin_sees_and_switches_marketplace_fees_with_the_effect_explained(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(StoreSettings::class)
            ->assertFormSet(['monetization_enabled' => true])
            ->assertSee('no commission on sales, no subscription bills and no listing limits')
            ->fillForm(['monetization_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(app(StoreConfigurationService::class)->isMonetizationEnabled());
    }
}
