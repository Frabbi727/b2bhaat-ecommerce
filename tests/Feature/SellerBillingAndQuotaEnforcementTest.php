<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Models\Category;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\Unit;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SellerBillingAndQuotaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected SellerProfile $profile;

    protected SellerPlan $plan;

    protected SellerSubscription $subscription;

    protected Category $category;

    protected Unit $unit;

    protected SalesChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $this->seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $this->profile = SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Kushtia Handloom Fabric',
            'store_slug' => 'kushtia-handloom',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $this->plan = SellerPlan::create([
            'name' => 'Starter 1 Item Quota',
            'slug' => 'starter-1',
            'subscription_price' => 0.00,
            'max_active_products' => 1,
            'is_listing_limit_enabled' => true,
            'is_active' => true,
        ]);

        $this->subscription = SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);

        $this->category = Category::create(['name' => 'Fabrics', 'slug' => 'fabrics']);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->channel = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
    }

    public function test_seller_can_view_subscription_hub_and_upgrade_plan(): void
    {
        $this->actingAs($this->seller);

        $proPlan = SellerPlan::create([
            'name' => 'Factory Pro',
            'slug' => 'factory_pro',
            'subscription_price' => 1299.00,
            'max_active_products' => 300,
            'is_active' => true,
        ]);

        Livewire::test(SubscriptionBilling::class)
            ->assertSuccessful()
            ->assertSee('Starter 1 Item Quota')
            ->call('upgradePlan', $proPlan->id);

        $this->assertDatabaseHas('seller_invoices', [
            'seller_id' => $this->seller->id,
            'total_amount' => '1299.00',
            'status' => 'unpaid',
        ]);
    }

    public function test_seller_can_pay_invoice_with_seller_wallet_balance(): void
    {
        $this->actingAs($this->seller);

        $wallet = SellerWallet::create([
            'seller_id' => $this->seller->id,
            'available_balance' => 2000.00,
            'escrow_held_balance' => 0.00,
        ]);

        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-WALLET-001',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 500.00,
            'total_amount' => 500.00,
            'status' => 'unpaid',
            'due_date' => now()->toDateString(),
        ]);

        Livewire::test(SubscriptionBilling::class)
            ->call('payWithWallet', $invoice->id);

        $this->assertEquals('paid', $invoice->fresh()->status);
        $this->assertEquals(1500.00, (float) $wallet->fresh()->available_balance);
        $this->assertDatabaseHas('seller_payments', [
            'invoice_id' => $invoice->id,
            'payment_method' => 'wallet_balance',
            'status' => 'successful',
        ]);
    }

    public function test_seller_cannot_publish_product_exceeding_active_quota(): void
    {
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $undoRepeaterFake = Repeater::fake();

        $productData1 = [
            'title' => 'Saree 1',
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->channel->id,
            'unit_id' => $this->unit->id,
            'regular_price' => 2500.00,
            'has_variations' => false,
            'simple_stock_quantity' => 10,
            'is_active' => true,
            'prices' => [[
                'sales_channel_id' => $this->channel->id,
                'unit_price' => 2500.00,
                'min_quantity' => 1,
                'max_quantity' => null,
            ]],
        ];

        // First product occupies the 1 slot
        Livewire::test(CreateProduct::class)
            ->fillForm($productData1)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', [
            'seller_id' => $this->seller->id,
            'title' => 'Saree 1',
            'is_active' => true,
        ]);

        $productData2 = [
            'title' => 'Saree 2 Exceeding Quota',
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->channel->id,
            'unit_id' => $this->unit->id,
            'regular_price' => 3000.00,
            'has_variations' => false,
            'simple_stock_quantity' => 10,
            'is_active' => true,
            'prices' => [[
                'sales_channel_id' => $this->channel->id,
                'unit_price' => 3000.00,
                'min_quantity' => 1,
                'max_quantity' => null,
            ]],
        ];

        // Second product publication must be halted because quota (1) is reached
        Livewire::test(CreateProduct::class)
            ->fillForm($productData2)
            ->call('create');

        $undoRepeaterFake();

        $this->assertDatabaseMissing('products', [
            'title' => 'Saree 2 Exceeding Quota',
        ]);
    }

    public function test_seller_can_toggle_auto_renew(): void
    {
        $this->actingAs($this->seller);
        $this->subscription->update(['auto_renew' => true]);

        $this->assertTrue((bool) $this->subscription->fresh()->auto_renew);

        Livewire::test(SubscriptionBilling::class)
            ->call('toggleAutoRenew');

        $this->assertFalse((bool) $this->subscription->fresh()->auto_renew);

        Livewire::test(SubscriptionBilling::class)
            ->call('toggleAutoRenew');

        $this->assertTrue((bool) $this->subscription->fresh()->auto_renew);
    }

    public function test_seller_sees_paid_invoices_in_history(): void
    {
        $this->actingAs($this->seller);

        $paidInvoice = SellerInvoice::create([
            'invoice_number' => 'SINV-PAID-001',
            'seller_id' => $this->seller->id,
            'subscription_id' => $this->subscription->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 250.00,
            'total_amount' => 250.00,
            'status' => SellerInvoice::STATUS_PAID,
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        Livewire::test(SubscriptionBilling::class)
            ->assertSuccessful()
            ->assertSee('SINV-PAID-001')
            ->assertSee('Settled Billing History');
    }
}
