<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Filament\Admin\Resources\AccountingAccounts\AccountingAccountResource;
use App\Filament\Admin\Resources\AccountingAccounts\Pages\CreateAccountingAccount;
use App\Filament\Admin\Resources\AccountingAccounts\Pages\EditAccountingAccount;
use App\Filament\Admin\Resources\AccountingAccounts\Pages\ListAccountingAccounts;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingAccountGroup;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAccountingAccountResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_access_chart_of_accounts_page_and_see_seeded_accounts(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $cashAccount = AccountingAccount::where('system_slug', 'cash_on_hand')->firstOrFail();
        $bankAccount = AccountingAccount::where('system_slug', 'bank_operating')->firstOrFail();
        $revenueAccount = AccountingAccount::where('system_slug', 'commission_revenue')->firstOrFail();

        $response = $this->get(AccountingAccountResource::getUrl('index'));
        $response->assertSuccessful();
        $response->assertSee('Cash in Hand');
        $response->assertSee('1010');
        $response->assertSee('Bank Account (Platform Operating)');
        $response->assertSee('1020');

        Livewire::test(ListAccountingAccounts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$cashAccount, $bankAccount])
            ->searchTable('Commission')
            ->assertCanSeeTableRecords([$revenueAccount]);
    }

    public function test_admin_can_filter_by_account_type(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $assetAccount = AccountingAccount::where('system_slug', 'cash_on_hand')->firstOrFail();
        $revenueAccount = AccountingAccount::where('system_slug', 'commission_revenue')->firstOrFail();

        Livewire::test(ListAccountingAccounts::class)
            ->filterTable('type', AccountType::ASSET->value)
            ->assertCanSeeTableRecords([$assetAccount])
            ->assertCanNotSeeTableRecords([$revenueAccount])
            ->filterTable('type', AccountType::REVENUE->value)
            ->assertCanSeeTableRecords([$revenueAccount])
            ->assertCanNotSeeTableRecords([$assetAccount]);
    }

    public function test_admin_can_create_a_custom_non_system_account(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $group = AccountingAccountGroup::where('code_prefix', '10')->firstOrFail();

        Livewire::test(CreateAccountingAccount::class)
            ->fillForm([
                'account_group_id' => $group->id,
                'type' => AccountType::ASSET->value,
                'code' => '1099',
                'name' => 'Petty Cash Office',
                'normal_balance' => NormalBalance::DEBIT->value,
                'description' => 'Office daily small cash fund',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('accounting_accounts', [
            'code' => '1099',
            'name' => 'Petty Cash Office',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
            'is_system' => false,
            'is_active' => true,
            'group_id' => $group->id,
        ]);
    }

    public function test_system_accounts_cannot_be_deleted(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $systemAccount = AccountingAccount::where('system_slug', 'cash_on_hand')->firstOrFail();
        $this->assertTrue($systemAccount->is_system);

        // 1. DeleteAction on table list is hidden for system accounts
        Livewire::test(ListAccountingAccounts::class)
            ->assertTableActionHidden(DeleteAction::class, $systemAccount);

        // 2. DeleteAction on edit page is hidden for system accounts
        Livewire::test(EditAccountingAccount::class, ['record' => $systemAccount->getKey()])
            ->assertActionHidden(DeleteAction::class);

        // 3. Model deleting event cancels deletion
        $deleted = $systemAccount->delete();
        $this->assertFalse($deleted);
        $this->assertDatabaseHas('accounting_accounts', ['id' => $systemAccount->id]);
    }

    public function test_custom_account_can_be_deleted_when_no_journal_lines_exist(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $group = AccountingAccountGroup::where('code_prefix', '10')->firstOrFail();
        $customAccount = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1098',
            'name' => 'Temporary Transit',
            'type' => AccountType::ASSET,
            'normal_balance' => NormalBalance::DEBIT,
            'is_system' => false,
            'is_active' => true,
        ]);

        Livewire::test(ListAccountingAccounts::class)
            ->assertTableActionVisible(DeleteAction::class, $customAccount)
            ->callTableAction(DeleteAction::class, $customAccount);

        $this->assertDatabaseMissing('accounting_accounts', ['id' => $customAccount->id]);
    }

    public function test_custom_account_with_journal_lines_cannot_be_deleted(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $group = AccountingAccountGroup::where('code_prefix', '10')->firstOrFail();
        $customAccount = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1097',
            'name' => 'Used Custom Account',
            'type' => AccountType::ASSET,
            'normal_balance' => NormalBalance::DEBIT,
            'is_system' => false,
            'is_active' => true,
        ]);

        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000099',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'idemp_del_test_1',
            'description' => 'Test entry for account deletion guard',
        ]);

        AccountingJournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $customAccount->id,
            'debit' => 150.00,
            'credit' => 0.00,
        ]);

        Livewire::test(ListAccountingAccounts::class)
            ->assertTableActionHidden(DeleteAction::class, $customAccount);

        $this->assertFalse($customAccount->delete());
        $this->assertDatabaseHas('accounting_accounts', ['id' => $customAccount->id]);
    }

    public function test_editing_system_account_preserves_immutable_fields(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $systemAccount = AccountingAccount::where('system_slug', 'cash_on_hand')->firstOrFail();

        Livewire::test(EditAccountingAccount::class, ['record' => $systemAccount->getKey()])
            ->assertFormFieldIsDisabled('code')
            ->assertFormFieldIsDisabled('type')
            ->assertFormFieldIsDisabled('normal_balance')
            ->fillForm([
                'name' => 'Cash in Hand (HQ & Retail)',
                'description' => 'Updated operational cash description',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $systemAccount->refresh();
        $this->assertSame('Cash in Hand (HQ & Retail)', $systemAccount->name);
        $this->assertSame('1010', $systemAccount->code);
        $this->assertSame(AccountType::ASSET, $systemAccount->type);
        $this->assertTrue($systemAccount->is_system);
    }
}
