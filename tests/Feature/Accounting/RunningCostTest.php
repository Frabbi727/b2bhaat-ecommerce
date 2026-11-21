<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Exceptions\Accounting\SettlementException;
use App\Filament\Admin\Pages\MoneyOverviewPage;
use App\Filament\Admin\Resources\AccountingJournalEntries\Actions\RecordRunningCostAction;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\MoneyOverviewService;
use App\Services\Accounting\RunningCostService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F7 (finance audit 2026-11-20): running costs the owner records come off profit and off the account paid from.
 */
class RunningCostTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]);
        $this->actingAs($this->admin);
    }

    private function today(): string
    {
        return now(config('app.display_timezone'))->toDateString();
    }

    public function test_a_cost_lowers_profit_and_the_account_it_was_paid_from(): void
    {
        app(RunningCostService::class)->record('hosting_expense', 1500.00, 'bank_operating', $this->today(), 'November hosting', $this->admin, 'k1');

        $overview = app(MoneyOverviewService::class)->overview($this->today(), $this->today());

        $this->assertSame(1500.0, $overview['costs']['total']);
        $this->assertSame(-1500.0, $overview['profit']);
        $this->assertSame(-1500.0, $overview['money_we_hold']['total']);
        $this->assertSame([['label' => 'Hosting & Domain', 'amount' => 1500.0]], $overview['costs']['parts']);
        $this->assertTrue(app(RunningCostService::class)->hasCostsBetween($this->today(), $this->today()));
    }

    public function test_the_same_form_submitted_twice_records_one_cost(): void
    {
        $service = app(RunningCostService::class);
        $service->record('sms_expense', 500.00, 'gateway_clearing', $this->today(), '', $this->admin, 'same');
        $service->record('sms_expense', 500.00, 'gateway_clearing', $this->today(), '', $this->admin, 'same');

        $this->assertSame(1, AccountingJournalEntry::where('financial_event', 'running_cost_paid')->count());
    }

    public function test_a_future_date_or_unknown_category_is_refused(): void
    {
        $this->expectException(SettlementException::class);

        app(RunningCostService::class)->record('hosting_expense', 100.00, 'bank_operating', now(config('app.display_timezone'))->addDay()->toDateString(), '', $this->admin, 'k2');
    }

    public function test_a_reversed_cost_no_longer_counts(): void
    {
        $entry = app(RunningCostService::class)->record('staff_expense', 8000.00, 'cash_on_hand', $this->today(), '', $this->admin, 'k3');
        app(JournalPostingService::class)->reverse($entry, 'Entered twice', $this->admin);

        $overview = app(MoneyOverviewService::class)->overview($this->today(), $this->today());

        $this->assertSame(0.0, $overview['costs']['total']);
        $this->assertFalse(app(RunningCostService::class)->hasCostsBetween($this->today(), $this->today()));
    }

    public function test_the_money_page_records_a_cost_and_stops_saying_costs_are_missing(): void
    {
        Livewire::test(MoneyOverviewPage::class)
            ->assertSee('profit before running costs')
            ->callAction(RecordRunningCostAction::NAME, data: [
                'category' => 'marketing_expense',
                'amount' => 2000,
                'date' => $this->today(),
                'paid_from' => 'bank_operating',
                'note' => 'Facebook ads',
            ])
            ->assertHasNoActionErrors()
            ->assertDontSee('profit before running costs')
            ->assertSee('Marketing');

        $this->assertDatabaseHas('accounting_journal_entries', [
            'financial_event' => 'running_cost_paid',
            'description' => 'Running cost: Marketing & ads (Facebook ads)',
        ]);
    }
}
