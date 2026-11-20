<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap 5.1 (WA-ADM-02): a confirmed cash-on-delivery order is not paid, so the status reads "Confirmed".
 * Only the label changes; the slug and every order stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_statuses')->where('slug', 'confirmed')->update(['name' => 'Confirmed', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('order_statuses')->where('slug', 'confirmed')->update(['name' => 'Confirmed & Paid', 'updated_at' => now()]);
    }
};
