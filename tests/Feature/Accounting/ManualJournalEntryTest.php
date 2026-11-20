<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Filament\Admin\Resources\AccountingJournalEntries\AccountingJournalEntryResource;
use App\Filament\Admin\Resources\AccountingJournalEntries\Pages\ListAccountingJournalEntries;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX-ACC-1: an admin can post a balanced manual journal from the journal list, with a confirmation and an audit entry.
 */
class ManualJournalEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $cashId;

    private int $bankId;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->cashId = (int) AccountingAccount::where('system_slug', 'cash_on_hand')->value('id');
        $this->bankId = (int) AccountingAccount::where('system_slug', 'bank_operating')->value('id');
    }

    /**
     * @param  list<array{account_id: int, debit: float|int|string|null, credit: float|int|string|null}>  $lines
     * @return array<string, mixed>
     */
    private function journalData(array $lines, array $overrides = []): array
    {
        return array_merge([
            'entry_date' => now()->format('Y-m-d'),
            'description' => 'Cash deposited into the bank',
            'reference' => 'BANK-SLIP-0042',
            'lines' => array_map(fn (array $line): array => $line + ['narration' => null], $lines),
            'confirmed' => true,
        ], $overrides);
    }

    /**
     * @return list<array{account_id: int, debit: float|int|string|null, credit: float|int|string|null}>
     */
    private function balancedLines(): array
    {
        return [
            ['account_id' => $this->bankId, 'debit' => '1500.50', 'credit' => null],
            ['account_id' => $this->cashId, 'debit' => null, 'credit' => '1500.50'],
        ];
    }

    public function test_an_admin_posts_a_balanced_manual_journal(): void
    {
        $undo = Repeater::fake();

        Livewire::test(ListAccountingJournalEntries::class)
            ->assertActionVisible('createManualJournal')
            ->callAction('createManualJournal', data: $this->journalData($this->balancedLines()))
            ->assertHasNoActionErrors()
            ->assertNotified();

        $undo();

        $entry = AccountingJournalEntry::with('lines')->sole();
        $this->assertSame(AccountingEvent::MANUAL_JOURNAL->value, $entry->financial_event);
        $this->assertSame(JournalEntryStatus::POSTED, $entry->status);
        $this->assertSame($this->admin->id, $entry->created_by_user_id);
        $this->assertSame('Cash deposited into the bank', $entry->description);
        $this->assertSame('BANK-SLIP-0042', $entry->metadata['reference'] ?? null);
        $this->assertCount(2, $entry->lines);
        $this->assertSame(1500.5, (float) $entry->lines->sum('debit'));
        $this->assertSame(1500.5, (float) $entry->lines->sum('credit'));

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'accounting',
            'action' => 'manual_journal_posted',
            'auditable_id' => $entry->id,
        ]);

        // ACC-5: a manual journal is the kind that can be reversed from the panel.
        Livewire::test(ListAccountingJournalEntries::class)->assertTableActionVisible('reverse', $entry);
    }

    public function test_an_unbalanced_journal_is_refused_and_nothing_is_posted(): void
    {
        $undo = Repeater::fake();

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('createManualJournal', data: $this->journalData([
                ['account_id' => $this->bankId, 'debit' => '1500', 'credit' => null],
                ['account_id' => $this->cashId, 'debit' => null, 'credit' => '1400'],
            ]))
            ->assertHasActionErrors(['lines']);

        $undo();

        $this->assertSame(0, AccountingJournalEntry::count());
    }

    public function test_a_line_with_both_debit_and_credit_is_refused(): void
    {
        $undo = Repeater::fake();

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('createManualJournal', data: $this->journalData([
                ['account_id' => $this->bankId, 'debit' => '100', 'credit' => '100'],
                ['account_id' => $this->cashId, 'debit' => '50', 'credit' => null],
                ['account_id' => $this->cashId, 'debit' => null, 'credit' => '50'],
            ]))
            ->assertHasActionErrors(['lines']);

        $undo();

        $this->assertSame(0, AccountingJournalEntry::count());
    }

    public function test_posting_needs_the_confirmation_tick(): void
    {
        $undo = Repeater::fake();

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('createManualJournal', data: $this->journalData($this->balancedLines(), ['confirmed' => false]))
            ->assertHasActionErrors(['confirmed']);

        $undo();

        $this->assertSame(0, AccountingJournalEntry::count());
    }

    public function test_an_inactive_account_is_refused(): void
    {
        AccountingAccount::whereKey($this->cashId)->update(['is_active' => false]);
        $undo = Repeater::fake();

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('createManualJournal', data: $this->journalData($this->balancedLines()))
            ->assertHasActionErrors(['lines.1.account_id']);

        $undo();

        $this->assertSame(0, AccountingJournalEntry::count());
    }

    public function test_a_date_in_a_closed_period_is_refused(): void
    {
        $year = AccountingFiscalYear::create(['name' => 'FY 2025-2026', 'start_date' => '2025-07-01', 'end_date' => '2026-06-30']);
        AccountingPeriod::create([
            'fiscal_year_id' => $year->id,
            'period_number' => 12,
            'name' => 'June 2026',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'is_closed' => true,
        ]);
        $undo = Repeater::fake();

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('createManualJournal', data: $this->journalData($this->balancedLines(), ['entry_date' => '2026-06-15']))
            ->assertNotified('Journal not posted');

        $undo();

        $this->assertSame(0, AccountingJournalEntry::count());
    }

    public function test_a_future_date_is_refused(): void
    {
        $undo = Repeater::fake();

        Livewire::test(ListAccountingJournalEntries::class)
            ->callAction('createManualJournal', data: $this->journalData($this->balancedLines(), ['entry_date' => now()->addDay()->format('Y-m-d')]))
            ->assertHasActionErrors(['entry_date']);

        $undo();

        $this->assertSame(0, AccountingJournalEntry::count());
    }

    public function test_a_seller_cannot_open_the_journal_pages(): void
    {
        $seller = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id]);

        $this->actingAs($seller)
            ->get(AccountingJournalEntryResource::getUrl('index'))
            ->assertForbidden();
    }
}
