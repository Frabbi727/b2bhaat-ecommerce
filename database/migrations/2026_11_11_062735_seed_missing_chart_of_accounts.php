<?php

use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A site that already took orders before accounting was added never got the chart of accounts (it comes from a
 * seeder), so delivery, payment and refund postings fail with "No query results for model AccountingAccount".
 * This adds the standard chart there. A fresh database (no orders yet) is left to the seeders as before, and a site
 * that already has its chart is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasOrders = DB::table('orders')->exists();
        $hasChart = DB::table('accounting_accounts')->whereNotNull('system_slug')->exists();

        if (! $hasOrders || $hasChart) {
            return;
        }

        (new AccountingChartOfAccountsSeeder)->run();
    }

    /**
     * Nothing to undo: accounts may already carry postings, and the seeders would add them again anyway.
     */
    public function down(): void {}
};
