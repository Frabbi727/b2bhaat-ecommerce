<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5.5 (WA-ADM-05): when an admin took wholesale access away from an approved buyer, so the SMS and the
 * admin screens say "withdrawn" rather than "application not approved".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('wholesale_withdrawn_at')->nullable()->after('wholesale_rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('wholesale_withdrawn_at');
        });
    }
};
