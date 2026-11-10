<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Accounting\AccountingAccount;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACC-20: the rules that protect the chart of accounts held only in the admin form. Any other code could change a
 * used account's type or balance side, or switch off a system account, and every statement would be wrong. The
 * model now refuses those changes wherever they come from.
 */
class ChartOfAccountsModelGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);
    }

    private function usedAccount(): AccountingAccount
    {
        $account = AccountingAccount::create([
            'group_id' => AccountingAccount::where('system_slug', 'bank_operating')->value('group_id'),
            'code' => '1999',
            'name' => 'Petty cash',
            'type' => AccountType::ASSET,
            'normal_balance' => NormalBalance::DEBIT,
            'is_system' => false,
            'is_active' => true,
        ]);

        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: JournalEntryData::businessDate(),
            financialEvent: 'manual_adjustment',
            idempotencyKey: 'acc20-used',
            description: 'Cash float',
            lines: [
                new JournalLineData(accountId: $account->id, debit: 50.00),
                new JournalLineData(accountSlug: 'bank_operating', credit: 50.00),
            ],
        ));

        return $account->fresh();
    }

    public function test_a_system_account_keeps_its_identity_and_stays_on(): void
    {
        $account = AccountingAccount::where('system_slug', 'commission_revenue')->firstOrFail();

        foreach ([
            ['system_slug' => 'something_else'],
            ['type' => AccountType::EXPENSE],
            ['normal_balance' => NormalBalance::DEBIT],
            ['code' => '9999'],
            ['is_system' => false],
            ['is_active' => false],
        ] as $change) {
            try {
                $account->fresh()->update($change);
                $this->fail('System account change was saved: '.json_encode($change));
            } catch (DomainException $e) {
                $this->assertStringContainsString($account->name, $e->getMessage());
            }
        }

        // Its name and description can still be edited
        $account->fresh()->update(['name' => 'Commission income', 'description' => 'Earned on delivery']);
        $this->assertSame('Commission income', $account->fresh()->name);
    }

    public function test_an_account_with_postings_keeps_its_type_balance_side_and_code(): void
    {
        $account = $this->usedAccount();

        foreach ([['type' => AccountType::EXPENSE], ['normal_balance' => NormalBalance::CREDIT], ['code' => '1998']] as $change) {
            try {
                $account->fresh()->update($change);
                $this->fail('Used account change was saved: '.json_encode($change));
            } catch (DomainException) {
                $this->assertSame(AccountType::ASSET, $account->fresh()->type);
            }
        }

        // A used non-system account can still be renamed or switched off
        $account->fresh()->update(['name' => 'Office petty cash', 'is_active' => false]);
        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_an_unused_account_can_still_be_corrected(): void
    {
        $account = AccountingAccount::create([
            'group_id' => AccountingAccount::where('system_slug', 'bank_operating')->value('group_id'),
            'code' => '1990',
            'name' => 'Typo account',
            'type' => AccountType::ASSET,
            'normal_balance' => NormalBalance::DEBIT,
        ]);

        $account->update(['type' => AccountType::EXPENSE, 'code' => '5990']);

        $this->assertSame(AccountType::EXPENSE, $account->fresh()->type);
    }
}
