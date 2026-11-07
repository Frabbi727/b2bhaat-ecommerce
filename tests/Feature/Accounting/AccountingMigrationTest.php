<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('accounting_account_groups'));
        $this->assertTrue(Schema::hasTable('accounting_accounts'));
        $this->assertTrue(Schema::hasTable('accounting_fiscal_years'));
        $this->assertTrue(Schema::hasTable('accounting_periods'));
        $this->assertTrue(Schema::hasTable('accounting_journal_entries'));
        $this->assertTrue(Schema::hasTable('accounting_journal_lines'));

        $this->assertTrue(Schema::hasColumns('accounting_account_groups', [
            'id', 'name', 'type', 'code_prefix', 'description', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('accounting_accounts', [
            'id', 'group_id', 'parent_id', 'code', 'system_slug', 'name', 'type', 'normal_balance', 'is_system', 'is_active', 'description', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('accounting_fiscal_years', [
            'id', 'name', 'start_date', 'end_date', 'is_closed', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('accounting_periods', [
            'id', 'fiscal_year_id', 'period_number', 'name', 'start_date', 'end_date', 'is_closed', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('accounting_journal_entries', [
            'id', 'journal_number', 'period_id', 'entry_date', 'posting_date', 'status', 'financial_event', 'reference_type', 'reference_id', 'idempotency_key', 'description', 'reversal_journal_id', 'created_by_user_id', 'metadata', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('accounting_journal_lines', [
            'id', 'journal_entry_id', 'account_id', 'debit', 'credit', 'seller_id', 'customer_id', 'sub_order_id', 'order_id', 'narration', 'created_at', 'updated_at',
        ]));
    }

    public function test_accounting_migrations_can_be_rolled_back(): void
    {
        $targetMigration = '2026_11_07_100001_create_accounting_account_groups_table';
        $steps = DB::table('migrations')->where('migration', '>=', $targetMigration)->count();

        $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('accounting_journal_lines'));
        $this->assertFalse(Schema::hasTable('accounting_journal_entries'));
        $this->assertFalse(Schema::hasTable('accounting_periods'));
        $this->assertFalse(Schema::hasTable('accounting_fiscal_years'));
        $this->assertFalse(Schema::hasTable('accounting_accounts'));
        $this->assertFalse(Schema::hasTable('accounting_account_groups'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('accounting_account_groups'));
        $this->assertTrue(Schema::hasTable('accounting_journal_lines'));
    }
}
