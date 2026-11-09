<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ACC-11: adds the account that holds commission on paid orders until they are delivered.
 * Only inserts the account when it is missing; no existing account or journal is changed. If code 2070 is already
 * used by another account, the next free code is used and printed (Wave 1 review M-6).
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

        if (DB::table('accounting_accounts')->where('system_slug', self::SLUG)->exists()) {
            return;
        }

        $code = $this->freeCode();

        if ($code !== self::CODE) {
            fwrite(STDERR, 'ACC-11: account code '.self::CODE." is already used by another account, so Deferred Commission Revenue was added as {$code}.\n");
        }

        DB::table('accounting_accounts')->insert([
            'group_id' => $groupId,
            'code' => $code,
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
     * 2070, or the next unused code up to 2099 when an admin already used it (Wave 1 review M-6). Commission postings
     * need this account, so it is never skipped.
     */
    private function freeCode(): string
    {
        $taken = DB::table('accounting_accounts')->where('code', 'like', '20%')->pluck('code')->map(fn ($code): string => (string) $code)->all();

        foreach (range((int) self::CODE, 2099) as $candidate) {
            if (! in_array((string) $candidate, $taken, true)) {
                return (string) $candidate;
            }
        }

        throw new RuntimeException('ACC-11: no free account code between '.self::CODE.' and 2099 for Deferred Commission Revenue. Free one, then run the migration again.');
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
