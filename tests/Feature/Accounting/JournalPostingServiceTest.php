<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\ImmutableJournalException;
use App\Exceptions\Accounting\InactiveAccountException;
use App\Exceptions\Accounting\UnbalancedJournalException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingPeriod;
use App\Models\User;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AccountingPeriod $activePeriod;

    protected AccountingPeriod $closedPeriod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $year = AccountingFiscalYear::create([
            'name' => 'FY 2026-2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
        ]);

        $this->activePeriod = AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'period_number' => 5,
            'name' => 'November 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'is_closed' => false,
        ]);

        $this->closedPeriod = AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'period_number' => 4,
            'name' => 'October 2026',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'is_closed' => true,
        ]);
    }

    public function test_balanced_entry_posts_atomically_and_updates_ledger(): void
    {
        $seller = User::factory()->create();
        $service = app(JournalPostingService::class);

        $entry = $service->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'test_order_1',
            description: 'Order #ORD-100 payment',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 1000.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 900.00, sellerId: $seller->id),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00, sellerId: $seller->id),
            ]
        ));

        $this->assertNotNull($entry->id);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertStringStartsWith('JRN-202611-', $entry->journal_number);
        $this->assertCount(3, $entry->lines);

        $gl = app(GeneralLedgerService::class);
        $this->assertSame(1000.00, $gl->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(900.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
        $this->assertSame(100.00, $gl->getBalanceForSlug('commission_revenue'));
    }

    public function test_repeated_call_with_same_idempotency_key_returns_existing_entry(): void
    {
        $service = app(JournalPostingService::class);

        $data = new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'idemp_unique_key',
            description: 'Duplicate test',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00),
            ]
        );

        $first = $service->post($data);
        $second = $service->post($data);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AccountingJournalEntry::where('idempotency_key', 'idemp_unique_key')->count());
    }

    public function test_posting_to_closed_period_throws_closed_period_exception(): void
    {
        $service = app(JournalPostingService::class);

        $this->expectException(ClosedPeriodException::class);

        $service->post(new JournalEntryData(
            entryDate: '2026-10-15',
            financialEvent: 'manual_journal',
            idempotencyKey: 'closed_period_key',
            description: 'Attempt to post in closed period',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00),
            ]
        ));
    }

    public function test_reversing_posted_entry_creates_inverse_entry_and_zeros_balances(): void
    {
        $seller = User::factory()->create();
        $service = app(JournalPostingService::class);
        $gl = app(GeneralLedgerService::class);

        $entry = $service->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'reversal_test_key',
            description: 'Original order payment',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 500.00, credit: 0.00),
                new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 0.00, credit: 500.00, sellerId: $seller->id),
            ]
        ));

        $this->assertSame(500.00, $gl->getBalanceForSlug('gateway_clearing'));

        $reversal = $service->reverse($entry, 'Customer cancelled order');

        $this->assertNotNull($reversal->id);
        $this->assertSame($entry->id, $reversal->reference_id);
        $this->assertSame(JournalEntryStatus::REVERSED, $entry->fresh()->status);
        $this->assertSame($reversal->id, $entry->fresh()->reversal_journal_id);

        // Balances should now be exactly zero
        $this->assertSame(0.00, $gl->getBalanceForSlug('gateway_clearing'));
        $this->assertSame(0.00, $gl->getBalanceForSlug('seller_escrow_payable', sellerId: $seller->id));
    }

    public function test_unbalanced_entry_throws_unbalanced_journal_exception(): void
    {
        $service = app(JournalPostingService::class);

        $this->expectException(UnbalancedJournalException::class);

        $service->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'manual_journal',
            idempotencyKey: 'unbalanced_key',
            description: 'Unbalanced entry',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 200.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00),
            ]
        ));
    }

    public function test_inactive_account_throws_inactive_account_exception(): void
    {
        $account = AccountingAccount::where('system_slug', 'gateway_clearing')->firstOrFail();
        $account->update(['is_active' => false]);

        $service = app(JournalPostingService::class);

        $this->expectException(InactiveAccountException::class);

        $service->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'inactive_account_key',
            description: 'Inactive account test',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00),
            ]
        ));
    }

    public function test_posted_journal_entry_enforces_immutability(): void
    {
        $service = app(JournalPostingService::class);

        $entry = $service->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'immutability_key',
            description: 'Immutability test',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00),
            ]
        ));

        $this->expectException(ImmutableJournalException::class);
        $entry->update(['description' => 'Tampered description']);
    }

    public function test_posted_journal_lines_cannot_be_added_or_deleted(): void
    {
        $service = app(JournalPostingService::class);

        $entry = $service->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'line_immutability_key',
            description: 'Line immutability test',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00),
            ]
        ));

        $line = $entry->lines->first();

        $this->expectException(ImmutableJournalException::class);
        $line->delete();
    }

    public function test_reversing_already_reversed_entry_returns_existing_reversal(): void
    {
        $service = app(JournalPostingService::class);

        $entry = $service->post(new JournalEntryData(
            entryDate: '2026-11-07',
            financialEvent: 'order_prepaid_received',
            idempotencyKey: 'double_reversal_key',
            description: 'Double reversal test',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 100.00, credit: 0.00),
                new JournalLineData(accountSlug: 'commission_revenue', debit: 0.00, credit: 100.00),
            ]
        ));

        $reversal1 = $service->reverse($entry, 'First reversal');
        $reversal2 = $service->reverse($entry->fresh(), 'Second reversal attempt');

        $this->assertSame($reversal1->id, $reversal2->id);
    }
}
