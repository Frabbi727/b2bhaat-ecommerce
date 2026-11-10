<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MON-13: each order line keeps the commission rate it was charged, so a later plan or rate change never makes an
 * old sale unexplainable. Lines from before this change stay empty (their rate was never recorded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('commission_rate');
        });
    }
};
