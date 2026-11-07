<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Exceptions\Accounting\ImmutableJournalException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingAccountGroup;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\Accounting\AccountingPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_and_journal_relationships_work(): void
    {
        $group = AccountingAccountGroup::create([
            'name' => 'Current Assets',
            'type' => AccountType::ASSET->value,
            'code_prefix' => '10',
        ]);

        $account = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1010',
            'system_slug' => 'cash_on_hand',
            'name' => 'Cash in Hand',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
            'is_system' => true,
        ]);

        $year = AccountingFiscalYear::create([
            'name' => 'FY 2026-2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
        ]);

        $period = AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'period_number' => 5,
            'name' => 'November 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ]);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202611-000001',
            'period_id' => $period->id,
            'entry_date' => '2026-11-07',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'manual_1',
            'description' => 'Opening entry',
        ]);

        $line = AccountingJournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 500.00,
            'credit' => 0.00,
        ]);

        $entry->update(['status' => JournalEntryStatus::POSTED->value]);

        $this->assertSame($group->id, $account->group->id);
        $this->assertSame($period->id, $entry->period->id);
        $this->assertCount(1, $entry->lines);
        $this->assertSame($account->id, $entry->lines->first()->account->id);
        $this->assertSame('500.00', (string) $line->debit);
    }

    public function test_posted_journal_entry_and_lines_cannot_be_updated_or_deleted(): void
    {
        $group = AccountingAccountGroup::create([
            'name' => 'Current Assets',
            'type' => 'asset',
            'code_prefix' => '10',
        ]);

        $account = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1010',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);

        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000001',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_1',
            'description' => 'Original description',
        ]);

        $line = AccountingJournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 100.00,
            'credit' => 0.00,
        ]);

        $entry->update(['status' => JournalEntryStatus::POSTED->value]);

        $this->expectException(ImmutableJournalException::class);
        $entry->update(['description' => 'Attempted tamper']);
    }

    public function test_posted_journal_entry_cannot_be_deleted(): void
    {
        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000002',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => 'posted',
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_2',
            'description' => 'Original description',
        ]);

        $this->expectException(ImmutableJournalException::class);
        $entry->delete();
    }

    public function test_posted_journal_lines_cannot_be_updated_or_deleted(): void
    {
        $group = AccountingAccountGroup::create([
            'name' => 'Current Assets',
            'type' => 'asset',
            'code_prefix' => '10',
        ]);

        $account = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1010',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);

        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000003',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_3',
            'description' => 'Original description',
        ]);

        $line = AccountingJournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 100.00,
            'credit' => 0.00,
        ]);

        $entry->update(['status' => JournalEntryStatus::POSTED->value]);

        try {
            $line->update(['debit' => 200.00]);
            $this->fail('Expected ImmutableJournalException on line update');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $line->delete();
            $this->fail('Expected ImmutableJournalException on line delete');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_journal_entry_allows_reversal_id_and_status_update(): void
    {
        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000004',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => 'posted',
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_4',
            'description' => 'Original description',
        ]);

        $reversal = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000005',
            'period_id' => $period->id,
            'entry_date' => '2026-01-16',
            'posting_date' => now(),
            'status' => 'posted',
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'reversal_key_5',
            'description' => 'Reversal description',
        ]);

        $entry->update([
            'status' => JournalEntryStatus::REVERSED->value,
            'reversal_journal_id' => $reversal->id,
        ]);

        $entry->refresh();
        $this->assertSame(JournalEntryStatus::REVERSED, $entry->status);
        $this->assertSame($reversal->id, $entry->reversalJournal->id);
    }

    public function test_all_model_relationships_and_foreign_keys_work(): void
    {
        $user = User::factory()->create();

        $group = AccountingAccountGroup::create([
            'name' => 'Current Assets',
            'type' => AccountType::ASSET->value,
            'code_prefix' => '10',
        ]);

        $parentAccount = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1000',
            'name' => 'Assets Parent',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
        ]);

        $childAccount = AccountingAccount::create([
            'group_id' => $group->id,
            'parent_id' => $parentAccount->id,
            'code' => '1010',
            'name' => 'Cash',
            'type' => AccountType::ASSET->value,
            'normal_balance' => NormalBalance::DEBIT->value,
        ]);

        $this->assertCount(2, $group->accounts);
        $this->assertSame($parentAccount->id, $childAccount->parent->id);
        $this->assertCount(1, $parentAccount->children);
        $this->assertSame($childAccount->id, $parentAccount->children->first()->id);

        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $this->assertCount(1, $year->periods);
        $this->assertSame($year->id, $period->fiscalYear->id);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000006',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT->value,
            'financial_event' => 'user_registered',
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'idempotency_key' => 'key_relations_test',
            'description' => 'Draft test entry',
            'created_by_user_id' => $user->id,
        ]);

        $line = AccountingJournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $childAccount->id,
            'debit' => 250.00,
            'credit' => 0.00,
            'seller_id' => $user->id,
            'customer_id' => $user->id,
            'narration' => 'Test line narration',
        ]);

        $this->assertSame($user->id, $entry->reference->id);
        $this->assertSame($user->id, $entry->createdByUser->id);
        $this->assertCount(1, $period->journalEntries);
        $this->assertCount(1, $childAccount->journalLines);

        $this->assertSame($user->id, $line->seller->id);
        $this->assertSame($user->id, $line->customer->id);
        $this->assertInstanceOf(BelongsTo::class, $line->subOrder());
        $this->assertInstanceOf(BelongsTo::class, $line->order());

        // Draft entry can be updated and deleted
        $entry->update(['description' => 'Updated draft description']);
        $this->assertSame('Updated draft description', $entry->fresh()->description);
        $line->update(['debit' => 300.00]);
        $this->assertSame('300.00', (string) $line->fresh()->debit);
        $line->delete();
        $this->assertDatabaseMissing('accounting_journal_lines', ['id' => $line->id]);
        $entry->delete();
        $this->assertDatabaseMissing('accounting_journal_entries', ['id' => $entry->id]);
    }

    public function test_tampering_posted_entry_with_disallowed_fields_during_reversal_fails(): void
    {
        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000007',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => 'posted',
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_7',
            'description' => 'Original description',
        ]);

        $this->expectException(ImmutableJournalException::class);
        $entry->update([
            'status' => JournalEntryStatus::REVERSED->value,
            'description' => 'Tampered description',
        ]);
    }

    public function test_reverting_posted_entry_to_draft_throws_immutable_journal_exception(): void
    {
        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000009',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::POSTED->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_9',
            'description' => 'Posted entry',
        ]);

        $this->expectException(ImmutableJournalException::class);
        $entry->update(['status' => JournalEntryStatus::DRAFT->value]);
    }

    public function test_creating_line_on_posted_journal_entry_throws_immutable_journal_exception(): void
    {
        $group = AccountingAccountGroup::create([
            'name' => 'Current Assets',
            'type' => 'asset',
            'code_prefix' => '10',
        ]);

        $account = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1010',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);

        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000010',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::POSTED->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_10',
            'description' => 'Posted entry',
        ]);

        $this->expectException(ImmutableJournalException::class);
        AccountingJournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => 100.00,
            'credit' => 0.00,
        ]);
    }

    public function test_reassigning_journal_entry_id_on_line_throws_immutable_journal_exception(): void
    {
        $group = AccountingAccountGroup::create([
            'name' => 'Current Assets',
            'type' => 'asset',
            'code_prefix' => '10',
        ]);

        $account = AccountingAccount::create([
            'group_id' => $group->id,
            'code' => '1010',
            'name' => 'Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);

        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry1 = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000011',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_11',
            'description' => 'Draft entry 1',
        ]);

        $entry2 = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000012',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::DRAFT->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_12',
            'description' => 'Draft entry 2',
        ]);

        $line = AccountingJournalLine::create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $account->id,
            'debit' => 100.00,
            'credit' => 0.00,
        ]);

        $this->expectException(ImmutableJournalException::class);
        $line->update(['journal_entry_id' => $entry2->id]);
    }

    public function test_reversed_journal_entry_cannot_be_updated_or_deleted(): void
    {
        $year = AccountingFiscalYear::create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $period = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);

        $entry = AccountingJournalEntry::create([
            'journal_number' => 'JRN-202601-000008',
            'period_id' => $period->id,
            'entry_date' => '2026-01-15',
            'posting_date' => now(),
            'status' => JournalEntryStatus::REVERSED->value,
            'financial_event' => 'manual_journal',
            'idempotency_key' => 'immutable_key_8',
            'description' => 'Reversed entry',
        ]);

        try {
            $entry->update(['description' => 'Should fail']);
            $this->fail('Expected exception on updating reversed entry');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('Expected exception on deleting reversed entry');
        } catch (ImmutableJournalException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }
}
