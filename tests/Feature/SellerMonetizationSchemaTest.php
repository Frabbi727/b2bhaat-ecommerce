<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\SellerSuspensionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SellerMonetizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_monetization_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('seller_plans'));
        $this->assertTrue(Schema::hasColumns('seller_plans', [
            'id', 'name', 'slug', 'description',
            'is_subscription_fee_enabled', 'subscription_price', 'billing_cycle_days',
            'is_listing_limit_enabled', 'max_active_products',
            'is_commission_enabled', 'retail_commission_rate', 'wholesale_commission_rate',
            'allow_listing_addons', 'badge_label', 'is_default_free_plan', 'is_active', 'sort_order',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('seller_subscriptions'));
        $this->assertTrue(Schema::hasColumns('seller_subscriptions', [
            'id', 'seller_id', 'plan_id', 'status',
            'starts_at', 'ends_at', 'grace_period_ends_at', 'auto_renew',
            'custom_product_limit', 'custom_retail_commission', 'custom_wholesale_commission',
            'custom_subscription_price', 'admin_override_notes',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('seller_invoices'));
        $this->assertTrue(Schema::hasColumns('seller_invoices', [
            'id', 'invoice_number', 'seller_id', 'subscription_id', 'invoice_type',
            'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
            'status', 'due_date', 'paid_at', 'metadata',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('seller_payments'));
        $this->assertTrue(Schema::hasColumns('seller_payments', [
            'id', 'invoice_id', 'seller_id', 'amount', 'payment_method',
            'transaction_reference', 'status', 'verified_by_user_id',
            'verified_at', 'receipt_url', 'notes',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('seller_suspension_events'));
        $this->assertTrue(Schema::hasColumns('seller_suspension_events', [
            'id', 'seller_id', 'trigger_type', 'snapshot_active_product_ids',
            'restored_product_ids', 'suspended_at', 'restored_at',
            'reason', 'action_by_user_id',
            'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumn('seller_profiles', 'monetization_status'));
        $this->assertTrue(Schema::hasColumn('products', 'was_active_before_suspension'));
    }

    public function test_monetization_models_and_relationships_work_correctly(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Apex Footwear Factory',
            'store_slug' => 'apex-footwear',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        $plan = SellerPlan::create([
            'name' => 'Growth Merchant',
            'slug' => 'growth',
            'subscription_price' => 499.00,
            'billing_cycle_days' => 30,
            'max_active_products' => 75,
            'retail_commission_rate' => 7.50,
            'wholesale_commission_rate' => 3.00,
            'is_active' => true,
        ]);

        $subscription = SellerSubscription::create([
            'seller_id' => $seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'auto_renew' => true,
        ]);

        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-202611-0001',
            'seller_id' => $seller->id,
            'subscription_id' => $subscription->id,
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499.00,
            'total_amount' => 499.00,
            'status' => 'paid',
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $payment = SellerPayment::create([
            'invoice_id' => $invoice->id,
            'seller_id' => $seller->id,
            'amount' => 499.00,
            'payment_method' => 'bkash',
            'transaction_reference' => 'TRX88291039',
            'status' => 'successful',
            'verified_at' => now(),
        ]);

        $suspensionEvent = SellerSuspensionEvent::create([
            'seller_id' => $seller->id,
            'trigger_type' => 'automated_billing',
            'snapshot_active_product_ids' => [1, 2, 3],
            'suspended_at' => now(),
            'reason' => 'Invoice overdue past grace period',
        ]);

        // Assert relationships
        $this->assertEquals($seller->id, $subscription->seller->id);
        $this->assertEquals($plan->id, $subscription->plan->id);
        $this->assertEquals($subscription->id, $invoice->subscription->id);
        $this->assertEquals($invoice->id, $payment->invoice->id);
        $this->assertEquals($seller->id, $suspensionEvent->seller->id);
        $this->assertEquals([1, 2, 3], $suspensionEvent->snapshot_active_product_ids);
        $this->assertTrue($seller->sellerSubscriptions()->exists());
        $this->assertEquals('active', $profile->fresh()->monetization_status);
    }

    public function test_monetization_migration_can_be_rolled_back(): void
    {
        $targetMigration = '2026_11_07_000001_create_seller_monetization_tables';
        $steps = DB::table('migrations')->where('migration', '>=', $targetMigration)->count();

        $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('seller_suspension_events'));
        $this->assertFalse(Schema::hasTable('seller_payments'));
        $this->assertFalse(Schema::hasTable('seller_invoices'));
        $this->assertFalse(Schema::hasTable('seller_subscriptions'));
        $this->assertFalse(Schema::hasTable('seller_plans'));
        $this->assertFalse(Schema::hasColumn('seller_profiles', 'monetization_status'));
        $this->assertFalse(Schema::hasColumn('products', 'was_active_before_suspension'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('seller_plans'));
        $this->assertTrue(Schema::hasColumn('seller_profiles', 'monetization_status'));
    }
}
