<?php

namespace Tests\Feature;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F2 (finance audit 2026-11-20): the admin dashboard's money cards come from the books. Income is the revenue
 * accounts net of reversals, and what we owe sellers is never netted against what sellers owe us.
 */
class AdminDashboardMoneyCardsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]));
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function postEntry(array $lines): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: JournalEntryData::businessDate(),
            financialEvent: 'manual_journal',
            idempotencyKey: 'f2-'.(++$this->sequence),
            description: 'Test entry',
            lines: $lines,
        ));
    }

    public function test_income_comes_from_the_books_with_reversals_taken_off(): void
    {
        $seller = User::factory()->create();

        $this->postEntry([
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 1060.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 900.00, sellerId: $seller->id),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 100.00),
            new JournalLineData(accountSlug: 'shipping_service_revenue', credit: 60.00),
        ]);
        // Part of the commission given back on a return
        $this->postEntry([
            new JournalLineData(accountSlug: 'commission_revenue', debit: 30.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 30.00, sellerId: $seller->id),
        ]);

        Livewire::test(AdminStatsOverview::class)
            ->assertSee('Our income this month')
            ->assertSee('৳130.00')
            ->assertSee('We owe sellers')
            ->assertSee('৳930.00');
    }

    public function test_sellers_who_owe_us_are_shown_separately(): void
    {
        $owed = User::factory()->create();
        $owing = User::factory()->create();

        $this->postEntry([
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 500.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 500.00, sellerId: $owed->id),
        ]);
        $this->postEntry([
            new JournalLineData(accountSlug: 'seller_wallet_payable', debit: 80.00, sellerId: $owing->id),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 80.00),
        ]);

        Livewire::test(AdminStatsOverview::class)
            ->assertSee('৳500.00')
            ->assertSee('1 seller owes us ৳80.00');
    }
}
