<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 1 review M-6: the ACC-11 migration must create the deferred commission account even when an admin already
 * used code 2070 for an account of their own, because commission postings need it.
 */
class DeferredCommissionAccountMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require database_path('migrations/2026_11_09_095029_add_deferred_commission_revenue_account.php'))->up();
    }

    private function removeDeferredAccount(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        DB::table('accounting_accounts')->where('system_slug', 'deferred_commission_revenue')->delete();
    }

    public function test_it_uses_the_next_free_code_when_2070_is_taken(): void
    {
        $this->removeDeferredAccount();
        DB::table('accounting_accounts')->insert([
            'group_id' => DB::table('accounting_account_groups')->where('code_prefix', '20')->value('id'),
            'code' => '2070', 'system_slug' => null, 'name' => 'Staff Advances Payable',
            'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $deferred = DB::table('accounting_accounts')->where('system_slug', 'deferred_commission_revenue')->first();
        $this->assertNotNull($deferred);
        $this->assertSame('2071', $deferred->code);
        $this->assertSame('Staff Advances Payable', DB::table('accounting_accounts')->where('code', '2070')->value('name'));
    }

    public function test_it_uses_2070_when_free_and_does_nothing_when_the_account_exists(): void
    {
        $this->removeDeferredAccount();

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(['2070'], DB::table('accounting_accounts')->where('system_slug', 'deferred_commission_revenue')->pluck('code')->all());
    }
}
