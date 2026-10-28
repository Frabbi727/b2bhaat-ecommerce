<?php

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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50)->unique();
            $table->timestamps();
        });

        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50)->unique();
            $table->string('badge_color', 20)->default('#3B82F6');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50)->unique();
            $table->string('badge_color', 20)->default('#6B7280');
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();
        });

        Schema::create('fulfillment_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50)->unique();
            $table->string('badge_color', 20)->default('#10B981');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50)->unique(); // 'retail', 'wholesale', 'both'
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('symbol', 10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('sales_channels');
        Schema::dropIfExists('fulfillment_statuses');
        Schema::dropIfExists('payment_statuses');
        Schema::dropIfExists('order_statuses');
        Schema::dropIfExists('roles');
    }
};
