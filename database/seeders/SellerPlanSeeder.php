<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use Illuminate\Database\Seeder;

class SellerPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free Starter',
                'slug' => 'free',
                'description' => 'Ideal for new sellers and cottage craft factories testing the wholesale marketplace.',
                'is_subscription_fee_enabled' => false,
                'subscription_price' => 0.00,
                'billing_cycle_days' => 365,
                'is_listing_limit_enabled' => true,
                'max_active_products' => 15,
                'is_commission_enabled' => true,
                'retail_commission_rate' => 10.00,
                'wholesale_commission_rate' => 4.00,
                'allow_listing_addons' => true,
                'badge_label' => 'Starter Seller',
                'is_default_free_plan' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Growth Merchant',
                'slug' => 'growth',
                'description' => 'For growing merchants wanting higher catalog capacity and reduced sales commissions.',
                'is_subscription_fee_enabled' => true,
                'subscription_price' => 499.00,
                'billing_cycle_days' => 30,
                'is_listing_limit_enabled' => true,
                'max_active_products' => 75,
                'is_commission_enabled' => true,
                'retail_commission_rate' => 7.50,
                'wholesale_commission_rate' => 3.00,
                'allow_listing_addons' => true,
                'badge_label' => 'Verified Merchant',
                'is_default_free_plan' => false,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Factory Pro',
                'slug' => 'factory_pro',
                'description' => 'Designed for wholesale factories, large manufacturers, and direct importers.',
                'is_subscription_fee_enabled' => true,
                'subscription_price' => 1299.00,
                'billing_cycle_days' => 30,
                'is_listing_limit_enabled' => true,
                'max_active_products' => 300,
                'is_commission_enabled' => true,
                'retail_commission_rate' => 5.00,
                'wholesale_commission_rate' => 2.00,
                'allow_listing_addons' => true,
                'badge_label' => 'Factory Partner',
                'is_default_free_plan' => false,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise / Brand',
                'slug' => 'enterprise',
                'description' => 'For national brand owners and volume distributors with unlimited product listings.',
                'is_subscription_fee_enabled' => true,
                'subscription_price' => 2999.00,
                'billing_cycle_days' => 30,
                'is_listing_limit_enabled' => false,
                'max_active_products' => null,
                'is_commission_enabled' => true,
                'retail_commission_rate' => 3.50,
                'wholesale_commission_rate' => 1.50,
                'allow_listing_addons' => true,
                'badge_label' => 'Official Brand',
                'is_default_free_plan' => false,
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $planData) {
            SellerPlan::updateOrCreate(['slug' => $planData['slug']], $planData);
        }

        $freePlan = SellerPlan::where('slug', 'free')->firstOrFail();

        // Backfill all existing approved seller profiles who do not have an active subscription
        $approvedProfiles = SellerProfile::where('is_approved', true)
            ->whereNotNull('user_id')
            ->get();

        foreach ($approvedProfiles as $profile) {
            $hasActiveSub = SellerSubscription::where('seller_id', $profile->user_id)
                ->whereIn('status', [SellerSubscription::STATUS_ACTIVE, SellerSubscription::STATUS_GRACE_PERIOD])
                ->exists();

            if (! $hasActiveSub) {
                SellerSubscription::create([
                    'seller_id' => $profile->user_id,
                    'plan_id' => $freePlan->id,
                    'status' => SellerSubscription::STATUS_ACTIVE,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays(365),
                    'auto_renew' => true,
                ]);
            }
        }
    }
}
