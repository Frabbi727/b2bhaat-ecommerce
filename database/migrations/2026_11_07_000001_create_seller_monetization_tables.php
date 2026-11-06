<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seller_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_subscription_fee_enabled')->default(true);
            $table->decimal('subscription_price', 12, 2)->default(0.00);
            $table->unsignedInteger('billing_cycle_days')->default(30);
            $table->boolean('is_listing_limit_enabled')->default(true);
            $table->unsignedInteger('max_active_products')->nullable();
            $table->boolean('is_commission_enabled')->default(true);
            $table->decimal('retail_commission_rate', 5, 2)->default(10.00);
            $table->decimal('wholesale_commission_rate', 5, 2)->default(4.00);
            $table->boolean('allow_listing_addons')->default(true);
            $table->string('badge_label', 50)->nullable();
            $table->boolean('is_default_free_plan')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('seller_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('seller_plans')->restrictOnDelete();
            $table->string('status', 30)->default('active');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('grace_period_ends_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->unsignedInteger('custom_product_limit')->nullable();
            $table->decimal('custom_retail_commission', 5, 2)->nullable();
            $table->decimal('custom_wholesale_commission', 5, 2)->nullable();
            $table->decimal('custom_subscription_price', 12, 2)->nullable();
            $table->text('admin_override_notes')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index('ends_at');
        });

        Schema::create('seller_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 50)->unique();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('seller_subscriptions')->nullOnDelete();
            $table->string('invoice_type', 30)->default('subscription_fee');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('total_amount', 12, 2);
            $table->string('status', 30)->default('unpaid');
            $table->date('due_date');
            $table->dateTime('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });

        Schema::create('seller_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('seller_invoices')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 50);
            $table->string('transaction_reference', 100)->nullable();
            $table->string('status', 30)->default('pending_verification');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('receipt_url', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
        });

        Schema::create('seller_suspension_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('trigger_type', 30);
            $table->json('snapshot_active_product_ids');
            $table->json('restored_product_ids')->nullable();
            $table->dateTime('suspended_at');
            $table->dateTime('restored_at')->nullable();
            $table->text('reason');
            $table->foreignId('action_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('seller_id');
        });

        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->string('monetization_status', 30)->default('active')->after('is_approved');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('was_active_before_suspension')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('was_active_before_suspension');
        });

        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->dropColumn('monetization_status');
        });

        Schema::dropIfExists('seller_suspension_events');
        Schema::dropIfExists('seller_payments');
        Schema::dropIfExists('seller_invoices');
        Schema::dropIfExists('seller_subscriptions');
        Schema::dropIfExists('seller_plans');
    }
};
