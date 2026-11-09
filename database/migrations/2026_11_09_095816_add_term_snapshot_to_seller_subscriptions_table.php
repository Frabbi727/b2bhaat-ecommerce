<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MON-2: each subscription keeps the plan terms it was sold on (price, billing cycle, listing limit, commission),
 * so editing a plan changes a seller's terms only from their next renewal.
 *
 * Existing subscriptions are filled from their plan's values at the time of the migration, which are the terms
 * they run on today. No other data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_subscriptions', function (Blueprint $table): void {
            $table->decimal('term_subscription_price', 12, 2)->nullable();
            $table->unsignedInteger('term_billing_cycle_days')->nullable();
            $table->boolean('term_is_listing_limit_enabled')->nullable();
            $table->unsignedInteger('term_max_active_products')->nullable();
            $table->boolean('term_is_commission_enabled')->nullable();
            $table->decimal('term_retail_commission_rate', 5, 2)->nullable();
            $table->decimal('term_wholesale_commission_rate', 5, 2)->nullable();
            $table->timestamp('terms_captured_at')->nullable();
        });

        $capturedAt = now();

        DB::table('seller_plans')->orderBy('id')->each(function (object $plan) use ($capturedAt): void {
            DB::table('seller_subscriptions')
                ->where('plan_id', $plan->id)
                ->whereNull('terms_captured_at')
                ->update([
                    'term_subscription_price' => $plan->subscription_price,
                    'term_billing_cycle_days' => $plan->billing_cycle_days,
                    'term_is_listing_limit_enabled' => $plan->is_listing_limit_enabled,
                    'term_max_active_products' => $plan->max_active_products,
                    'term_is_commission_enabled' => $plan->is_commission_enabled,
                    'term_retail_commission_rate' => $plan->retail_commission_rate,
                    'term_wholesale_commission_rate' => $plan->wholesale_commission_rate,
                    'terms_captured_at' => $capturedAt,
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('seller_subscriptions', function (Blueprint $table): void {
            $table->dropColumn([
                'term_subscription_price',
                'term_billing_cycle_days',
                'term_is_listing_limit_enabled',
                'term_max_active_products',
                'term_is_commission_enabled',
                'term_retail_commission_rate',
                'term_wholesale_commission_rate',
                'terms_captured_at',
            ]);
        });
    }
};
