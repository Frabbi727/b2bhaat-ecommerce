<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 3.4 (WA-ORD-02): a buyer may ask B2Bhaat to review a return the seller rejected. The request is then
 * "escalated" until an admin decides, and the parcel's escrow stays on hold meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->timestamp('escalated_at')->nullable()->after('resolved_at');
            $table->text('escalation_note')->nullable()->after('escalated_at');
            $table->timestamp('escalation_decided_at')->nullable()->after('escalation_note');
            $table->index(['status', 'escalated_at']);
        });

        // Audit 1.6 listed the allowed statuses on PostgreSQL; "escalated" is new
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE return_requests DROP CONSTRAINT IF EXISTS return_requests_status_known');
            DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_status_known CHECK (status IN ('pending', 'approved', 'rejected', 'resolved', 'escalated'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE return_requests SET status = 'rejected' WHERE status = 'escalated'");
            DB::statement('ALTER TABLE return_requests DROP CONSTRAINT IF EXISTS return_requests_status_known');
            DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_status_known CHECK (status IN ('pending', 'approved', 'rejected', 'resolved'))");
        }

        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropIndex(['status', 'escalated_at']);
            $table->dropColumn(['escalated_at', 'escalation_note', 'escalation_decided_at']);
        });
    }
};
