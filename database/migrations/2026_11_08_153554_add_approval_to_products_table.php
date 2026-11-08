<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('draft')->after('is_active');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['approval_status', 'submitted_at']);
        });

        self::backfill();
    }

    /**
     * Grandfathers the existing catalog: every product becomes approved, dated at creation, with no reviewer.
     */
    public static function backfill(): void
    {
        DB::table('products')->orderBy('id')->select('id')->chunkById(1000, function ($products): void {
            DB::table('products')
                ->whereIn('id', $products->pluck('id'))
                ->update([
                    'approval_status' => 'approved',
                    'approved_at' => DB::raw('created_at'),
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['approval_status', 'submitted_at']);
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['approval_status', 'submitted_at', 'approved_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
