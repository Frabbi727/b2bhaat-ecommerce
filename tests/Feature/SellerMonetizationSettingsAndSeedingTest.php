<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\SellerPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerMonetizationSettingsAndSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_configuration_service_provides_dynamic_monetization_defaults(): void
    {
        $config = app(StoreConfigurationService::class);

        $this->assertTrue($config->isMonetizationEnabled());
        $this->assertEquals(3, $config->getMonetizationGracePeriodDays());
        $this->assertEquals(3, $config->getMonetizationPreExpiryNoticeDays());
        $this->assertTrue($config->isWalletAutoRenewAllowed());
        $this->assertEquals(25, $config->getListingAddonPackSize());
        $this->assertEquals(250.00, $config->getListingAddonPackPrice());
        $this->assertEquals(7, $config->getCodDeficitGraceDays());
    }

    public function test_seller_plan_seeder_creates_default_plans(): void
    {
        $this->seed(SellerPlanSeeder::class);

        $this->assertDatabaseHas('seller_plans', [
            'slug' => 'free',
            'name' => 'Free Starter',
            'subscription_price' => '0.00',
            'max_active_products' => 15,
            'retail_commission_rate' => '10.00',
            'wholesale_commission_rate' => '4.00',
            'is_default_free_plan' => true,
        ]);

        $this->assertDatabaseHas('seller_plans', [
            'slug' => 'growth',
            'name' => 'Growth Merchant',
            'subscription_price' => '499.00',
            'max_active_products' => 75,
            'retail_commission_rate' => '7.50',
            'wholesale_commission_rate' => '3.00',
        ]);

        $this->assertDatabaseHas('seller_plans', [
            'slug' => 'factory_pro',
            'name' => 'Factory Pro',
            'subscription_price' => '1299.00',
            'max_active_products' => 300,
            'retail_commission_rate' => '5.00',
            'wholesale_commission_rate' => '2.00',
        ]);

        $this->assertDatabaseHas('seller_plans', [
            'slug' => 'enterprise',
            'name' => 'Enterprise / Brand',
            'subscription_price' => '2999.00',
            'max_active_products' => null, // unlimited
            'retail_commission_rate' => '3.50',
            'wholesale_commission_rate' => '1.50',
        ]);
    }

    public function test_seeder_backfills_existing_approved_sellers_with_free_plan_subscription(): void
    {
        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $existingSeller = User::factory()->create([
            'role_id' => $sellerRole->id,
            'is_active' => true,
        ]);

        SellerProfile::create([
            'user_id' => $existingSeller->id,
            'store_name' => 'Heritage Jamdani Weavers',
            'store_slug' => 'heritage-jamdani',
            'is_approved' => true,
        ]);

        $this->seed(SellerPlanSeeder::class);

        $this->assertDatabaseHas('seller_subscriptions', [
            'seller_id' => $existingSeller->id,
            'status' => 'active',
        ]);

        $subscription = SellerSubscription::where('seller_id', $existingSeller->id)->first();
        $this->assertNotNull($subscription);
        $this->assertEquals('free', $subscription->plan->slug);
    }
}
