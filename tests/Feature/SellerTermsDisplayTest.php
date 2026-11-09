<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Pages\StoreSettings;
use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Monetization\SellerTermsSummary;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX-5: the seller panel shows the commission, listing limit and next payment the system actually charges.
 */
class SellerTermsDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private SellerPlan $plan;

    private SellerSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        // The rate set at approval; the plan below must win over it.
        $this->seller->sellerProfile->update(['commission_rate_percentage' => 10.00]);

        $this->plan = SellerPlan::create([
            'name' => 'Growth Merchant', 'slug' => 'growth-ux5',
            'is_subscription_fee_enabled' => true, 'subscription_price' => 499.00, 'billing_cycle_days' => 30,
            'is_listing_limit_enabled' => true, 'max_active_products' => 75,
            'is_commission_enabled' => true, 'retail_commission_rate' => 7.50, 'wholesale_commission_rate' => 3.00,
            'is_active' => true,
        ]);
        $this->subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id, 'plan_id' => $this->plan->id, 'status' => SellerSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(10), 'auto_renew' => true,
        ]);
        Product::where('seller_id', $this->seller->id)->update(['category_id' => Category::create(['name' => 'Plain', 'slug' => 'plain-ux5'])->id]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function terms(): array
    {
        return app(SellerTermsSummary::class)->forSeller($this->seller->fresh());
    }

    public function test_store_settings_shows_the_plan_rate_not_the_approval_rate(): void
    {
        Livewire::test(StoreSettings::class)
            ->assertSee('Retail 7.5% · Wholesale 3% of each sale')
            ->assertDontSee('10% of each sale');
    }

    public function test_the_shown_rates_match_what_an_order_is_charged(): void
    {
        $product = Product::where('seller_id', $this->seller->id)->with('category')->firstOrFail();
        $engine = app(MonetizationEngineService::class);

        foreach ([SalesChannel::RETAIL => 'retail_rate', SalesChannel::WHOLESALE => 'wholesale_rate'] as $slug => $key) {
            $charged = $engine->resolveCommissionRate($this->seller, $product, SalesChannel::where('slug', $slug)->firstOrFail());
            $this->assertSame($charged, $this->terms()[$key]);
        }

        $this->subscription->update(['custom_retail_commission' => 5.00]);
        $this->assertSame(5.0, $this->terms()['retail_rate']);
        $this->assertSame(3.0, $this->terms()['wholesale_rate']);
        $this->assertTrue($this->terms()['custom_rate']);
    }

    public function test_billing_page_shows_terms_and_the_renewal_payment(): void
    {
        Livewire::test(SubscriptionBilling::class)
            ->assertSee('Your terms')
            ->assertSee('7.5%')
            ->assertSee('৳499.00')
            ->assertSee('Renewal on '.$this->subscription->ends_at->format('d M Y'));
    }

    public function test_the_renewal_shows_the_plans_new_price(): void
    {
        $this->plan->update(['subscription_price' => 799.00]);

        $terms = $this->terms();

        $this->assertSame(799.0, $terms['next_payment_amount']);
        // The running period keeps its terms (MON-2), so the commission shown does not change yet.
        $this->assertSame(7.5, $terms['retail_rate']);
    }

    public function test_an_unpaid_bill_is_the_next_payment(): void
    {
        SellerInvoice::create([
            'seller_id' => $this->seller->id, 'subscription_id' => $this->subscription->id,
            'invoice_number' => 'INV-UX5-1', 'invoice_type' => 'subscription_fee',
            'subtotal' => 520, 'total_amount' => 520, 'status' => SellerInvoice::STATUS_UNPAID, 'due_date' => now()->addDays(2),
        ]);

        $terms = $this->terms();

        $this->assertSame(520.0, $terms['next_payment_amount']);
        $this->assertTrue($terms['next_payment_is_bill']);
    }

    public function test_with_fees_switched_off_no_commission_is_shown(): void
    {
        app(StoreConfigurationService::class)->set('monetization_enabled', false, 'monetization', 'boolean');

        $this->assertSame(0.0, $this->terms()['retail_rate']);

        Livewire::test(StoreSettings::class)
            ->assertSee('No commission while marketplace fees are switched off');
    }
}
