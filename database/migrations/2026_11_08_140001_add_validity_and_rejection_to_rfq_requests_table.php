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
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->timestamp('valid_until')->nullable()->index()->after('seller_notes');
            $table->unsignedInteger('dispatch_days')->nullable()->after('valid_until');
            $table->string('rejected_by', 32)->nullable()->after('dispatch_days');
            $table->text('rejection_reason')->nullable()->after('rejected_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->dropIndex(['valid_until']);
            $table->dropColumn([
                'valid_until',
                'dispatch_days',
                'rejected_by',
                'rejection_reason',
            ]);
        });
    }
};
