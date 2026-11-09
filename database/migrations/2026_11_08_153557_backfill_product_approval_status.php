<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INV-8: grandfathers the existing catalog as approved. Kept apart from the column migration and run outside a
 * transaction, so each 1000-row chunk commits on its own and the products table is never locked for the whole backfill.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Self-guarding: once the review workflow has been used here (anything submitted, rejected, reviewed or proposed),
     * nothing is touched, so live drafts and waiting products are never grandfathered. Rows the backfill itself
     * approved are not workflow activity, so a run that stopped partway simply resumes on the remaining drafts.
     */
    public function up(): void
    {
        if (self::approvalAlreadyLive()) {
            return;
        }

        self::backfill();
    }

    public static function approvalAlreadyLive(): bool
    {
        $productActivity = DB::table('products')
            ->where(fn ($query) => $query
                ->whereIn('approval_status', ['pending_review', 'rejected'])
                ->orWhereNotNull('submitted_at')
                ->orWhereNotNull('rejected_at')
                ->orWhereNotNull('approved_by_user_id'))
            ->exists();

        return $productActivity || DB::table('product_change_requests')->exists();
    }

    /**
     * Every draft product becomes approved, dated at creation, with no reviewer; `updated_at` is left untouched.
     */
    public static function backfill(): void
    {
        DB::table('products')->where('approval_status', 'draft')->orderBy('id')->select('id')->chunkById(1000, function ($products): void {
            DB::table('products')
                ->whereIn('id', $products->pluck('id'))
                ->update([
                    'approval_status' => 'approved',
                    'approved_at' => DB::raw('created_at'),
                ]);
        });
    }

    /**
     * Nothing to undo: the column migration's down() drops the approval columns.
     */
    public function down(): void {}
};
