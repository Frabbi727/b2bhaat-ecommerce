<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->jsonb('proposed_prices');
            $table->decimal('proposed_regular_price', 12, 2)->nullable();
            $table->foreignId('proposed_sales_channel_id')->nullable()->constrained('sales_channels');
            $table->foreignId('proposed_category_id')->nullable()->constrained('categories');
            $table->jsonb('base_prices');
            $table->decimal('base_regular_price', 12, 2)->nullable();
            $table->foreignId('base_sales_channel_id')->constrained('sales_channels');
            $table->foreignId('base_category_id')->constrained('categories');
            $table->string('base_fingerprint', 64);
            $table->string('seller_note', 500)->nullable();
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('product_change_requests')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['product_id', 'status']);
            $table->index(['seller_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX product_change_requests_one_pending ON product_change_requests (product_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_change_requests');
    }
};
