<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ACC-11: adds the account that holds commission on paid orders until they are delivered.
 * Only inserts the account when it is missing; no existing account or journal is changed.
 */
return new class extends Migration
{
    private const SLUG = 'deferred_commission_revenue';

    private const CODE = '2070';

    public function up(): void
    {
        $groupId = DB::table('accounting_account_groups')->where('code_prefix', '20')->value('id');

        // A fresh database has no chart yet; the chart seeder adds this account with the rest.
        if ($groupId === null) {
            return;
        }

        $exists = DB::table('accounting_accounts')
            ->where('system_slug', self::SLUG)
            ->orWhere('code', self::CODE)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('accounting_accounts')->insert([
            'group_id' => $groupId,
            'code' => self::CODE,
            'system_slug' => self::SLUG,
            'name' => 'Deferred Commission Revenue',
            'type' => 'liability',
            'normal_balance' => 'credit',
            'is_system' => true,
            'is_active' => true,
            'description' => 'Commission on paid orders not yet delivered; becomes income on delivery',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Removes the account only while nothing has been posted to it.
     */
    public function down(): void
    {
        $accountId = DB::table('accounting_accounts')->where('system_slug', self::SLUG)->value('id');

        if ($accountId === null || DB::table('accounting_journal_lines')->where('account_id', $accountId)->exists()) {
            return;
        }

        DB::table('accounting_accounts')->where('id', $accountId)->delete();
    }
};
