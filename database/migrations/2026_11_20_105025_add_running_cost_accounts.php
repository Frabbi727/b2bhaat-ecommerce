<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * F7 (finance audit 2026-11-20): accounts for the platform's own running costs, so profit can be after costs. The
 * owner chose these categories. An account is skipped (and logged) if its code is already used by an account an
 * admin created, so nothing existing is changed.
 */
return new class extends Migration
{
    /**
     * @return list<array{code: string, system_slug: string, name: string, description: string}>
     */
    private function accounts(): array
    {
        return [
            ['code' => '5040', 'system_slug' => 'hosting_expense', 'name' => 'Hosting & Domain', 'description' => 'Web hosting, domain and server costs'],
            ['code' => '5050', 'system_slug' => 'sms_expense', 'name' => 'SMS Costs', 'description' => 'SMS gateway (BulkSMSBD) top-ups'],
            ['code' => '5060', 'system_slug' => 'staff_expense', 'name' => 'Staff Costs', 'description' => 'Salaries and staff payments'],
            ['code' => '5070', 'system_slug' => 'marketing_expense', 'name' => 'Marketing', 'description' => 'Ads, promotions and marketing costs'],
            ['code' => '5080', 'system_slug' => 'other_running_expense', 'name' => 'Other Running Costs', 'description' => 'Running costs that fit no other category'],
        ];
    }

    public function up(): void
    {
        $groupId = DB::table('accounting_account_groups')->where('code_prefix', '50')->value('id');

        // A fresh database gets these from the chart of accounts seeder
        if ($groupId === null) {
            return;
        }

        foreach ($this->accounts() as $account) {
            if (DB::table('accounting_accounts')->where('system_slug', $account['system_slug'])->exists()) {
                continue;
            }

            if (DB::table('accounting_accounts')->where('code', $account['code'])->exists()) {
                Log::warning("Running cost account {$account['system_slug']} not added: code {$account['code']} is already used.");

                continue;
            }

            DB::table('accounting_accounts')->insert($account + [
                'group_id' => $groupId,
                'type' => 'expense',
                'normal_balance' => 'debit',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $slugs = array_column($this->accounts(), 'system_slug');

        $used = DB::table('accounting_journal_lines')
            ->join('accounting_accounts', 'accounting_accounts.id', '=', 'accounting_journal_lines.account_id')
            ->whereIn('accounting_accounts.system_slug', $slugs)
            ->exists();

        if (! $used) {
            DB::table('accounting_accounts')->whereIn('system_slug', $slugs)->delete();
        }
    }
};
