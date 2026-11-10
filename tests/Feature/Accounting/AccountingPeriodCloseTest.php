<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACC-14: finance can close a finished month so nothing more is booked into it, and only a super admin can reopen it.
 */
class AccountingPeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private AccountingPeriod $october;

    private AccountingPeriod $november;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));

        $year = AccountingFiscalYear::create(['name' => 'FY 2026-2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        $this->october = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 4, 'name' => 'October 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $this->november = AccountingPeriod::create(['fiscal_year_id' => $year->id, 'period_number' => 5, 'name' => 'November 2026', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'is_closed' => false]);

        $this->superAdmin = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id]);
    }

    private function postOn(string $date): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: $date,
            financialEvent: 'manual_adjustment',
            idempotencyKey: 'close-test-'.$date,
            description: 'Late entry',
            lines: [
                new JournalLineData(accountSlug: 'bank_operating', debit: 5.00),
                new JournalLineData(accountSlug: 'owner_equity', credit: 5.00),
            ],
        ));
    }

    public function test_a_closed_month_takes_no_more_entries_and_the_close_is_audited(): void
    {
        app(AccountingPeriodService::class)->close($this->october, $this->superAdmin, 'October books checked');

        $this->assertTrue($this->october->fresh()->is_closed);
        $this->assertDatabaseHas('audit_logs', ['action' => 'period_closed', 'auditable_id' => $this->october->id]);

        $this->expectException(ClosedPeriodException::class);
        $this->postOn('2026-10-15');
    }

    public function test_the_running_month_cannot_be_closed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('has not ended');

        app(AccountingPeriodService::class)->close($this->november, $this->superAdmin, 'Too early');
    }

    public function test_only_a_super_admin_can_reopen(): void
    {
        $service = app(AccountingPeriodService::class);
        $service->close($this->october, $this->superAdmin, 'Checked');
        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator'])->id]);

        try {
            $service->reopen($this->october->fresh(), $admin, 'Need to fix');
            $this->fail('An admin must not reopen a closed month.');
        } catch (DomainException) {
            $this->assertTrue($this->october->fresh()->is_closed);
        }

        $service->reopen($this->october->fresh(), $this->superAdmin, 'Correct a missed refund');
        $this->assertFalse($this->october->fresh()->is_closed);
        $this->assertDatabaseHas('audit_logs', ['action' => 'period_reopened', 'auditable_id' => $this->october->id]);
        $this->postOn('2026-10-15');
    }

    public function test_finance_closes_a_month_from_the_periods_screen(): void
    {
        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAccountingPeriods::class)
            ->assertCanSeeTableRecords([$this->october, $this->november])
            ->assertTableActionHidden('close', $this->november)
            ->callTableAction('close', $this->october, ['reason' => 'October reconciled'])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertTrue($this->october->fresh()->is_closed);
    }
}
