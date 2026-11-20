<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Filament\Admin\Pages\MoneyOverviewPage;
use App\Filament\Admin\Pages\SellerMoneyPage;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Accounting\FinancialStatementService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\MoneyOverviewService;
use App\Services\Accounting\SellerMoneyService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase B (2026-11-20 finance audit): the Money page and the seller money page show figures that come from the
 * books, agree with the financial statements, and keep "we owe sellers" apart from "sellers owe us".
 */
class MoneyOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private User $owed;

    private User $owing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
        ]));

        $this->owed = $this->seller('Owed Store');
        $this->owing = $this->seller('Owing Store');

        // A ৳1,060 cash-on-delivery parcel: ৳900 for the seller, ৳100 commission, ৳60 delivery
        $this->postEntry('cod_delivered', [
            new JournalLineData(accountSlug: 'courier_cod_receivable', debit: 1060.00),
            new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 900.00, sellerId: $this->owed->id),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 100.00),
            new JournalLineData(accountSlug: 'shipping_service_revenue', credit: 60.00),
        ]);
        // The courier pays us ৳1,010 into the bank and keeps ৳50
        $this->postEntry('courier_cod_settled', [
            new JournalLineData(accountSlug: 'bank_operating', debit: 1010.00),
            new JournalLineData(accountSlug: 'courier_delivery_expense', debit: 50.00),
            new JournalLineData(accountSlug: 'courier_cod_receivable', credit: 1060.00),
        ]);
        // 48 hours later the money is released to the seller's wallet
        $this->postEntry('escrow_released', [
            new JournalLineData(accountSlug: 'seller_escrow_payable', debit: 900.00, sellerId: $this->owed->id),
            new JournalLineData(accountSlug: 'seller_wallet_payable', credit: 900.00, sellerId: $this->owed->id),
        ]);
        // Another seller kept a buyer's cash and owes us ৳80 commission
        $this->postEntry('cod_self_delivered', [
            new JournalLineData(accountSlug: 'seller_wallet_payable', debit: 80.00, sellerId: $this->owing->id),
            new JournalLineData(accountSlug: 'commission_revenue', credit: 80.00),
        ]);

        // Unpaid bills: a plan fee counts; a commission bill does not (already in the balance)
        $this->bill($this->owing, SellerInvoice::TYPE_SUBSCRIPTION_FEE, 300.00, now()->subDays(5)->toDateString());
        $this->bill($this->owing, SellerInvoice::TYPE_COD_COMMISSION_DEFICIT, 80.00, now()->addDays(5)->toDateString());
    }

    private function seller(string $store): User
    {
        $user = User::factory()->create();
        SellerProfile::create(['user_id' => $user->id, 'store_name' => $store, 'store_slug' => str($store)->slug()->toString(), 'is_approved' => true]);

        return $user;
    }

    private function bill(User $seller, string $type, float $amount, string $due): void
    {
        SellerInvoice::create([
            'invoice_number' => 'SINV-T-'.(++$this->sequence),
            'seller_id' => $seller->id,
            'invoice_type' => $type,
            'subtotal' => $amount,
            'total_amount' => $amount,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => $due,
        ]);
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function postEntry(string $event, array $lines): void
    {
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: JournalEntryData::businessDate(),
            financialEvent: $event,
            idempotencyKey: 'money-'.(++$this->sequence),
            description: 'Test '.$event,
            lines: $lines,
        ));
    }

    private function today(): string
    {
        return now(config('app.display_timezone'))->toDateString();
    }

    public function test_the_figures_come_from_the_books_and_agree_with_the_statements(): void
    {
        $overview = app(MoneyOverviewService::class)->overview($this->today(), $this->today());

        $this->assertSame(1010.0, $overview['money_we_hold']['total']);
        $this->assertSame(900.0, $overview['owed_to_sellers']['total']);
        $this->assertSame(900.0, $overview['owed_to_sellers']['ready']);
        $this->assertSame(0.0, $overview['owed_to_sellers']['on_hold']);
        $this->assertSame(80.0, $overview['owed_by_sellers']['from_balances']);
        $this->assertSame(300.0, $overview['owed_by_sellers']['unpaid_bills']);
        $this->assertSame(380.0, $overview['owed_by_sellers']['total']);
        $this->assertSame(0.0, $overview['courier_cash']);
        $this->assertSame(240.0, $overview['income']['total']);
        $this->assertSame(50.0, $overview['costs']['total']);
        $this->assertSame(190.0, $overview['profit']);

        $statement = app(FinancialStatementService::class)->getIncomeStatement($this->today(), $this->today());
        $this->assertSame($statement['net_income'], $overview['profit']);
    }

    public function test_a_balance_is_read_as_of_the_end_date(): void
    {
        $yesterday = now(config('app.display_timezone'))->subDay()->toDateString();
        $overview = app(MoneyOverviewService::class)->overview($yesterday, $yesterday);

        $this->assertSame(0.0, $overview['money_we_hold']['total']);
        $this->assertSame(0.0, $overview['owed_to_sellers']['total']);
        $this->assertSame(0.0, $overview['income']['total']);
    }

    public function test_the_follow_up_list_names_sellers_who_owe_us(): void
    {
        $followUp = app(MoneyOverviewService::class)->followUp();

        $this->assertSame([['seller_id' => $this->owing->id, 'seller' => 'Owing Store', 'total' => 80.0]], $followUp['sellers_owing']);
        $this->assertCount(1, $followUp['overdue_bills']);
        $this->assertSame(300.0, $followUp['overdue_bills'][0]['total']);
    }

    public function test_the_money_page_renders_for_an_admin(): void
    {
        Livewire::test(MoneyOverviewPage::class)
            ->assertSee('Money we hold')
            ->assertSee('৳1,010.00')
            ->assertSee('We owe sellers')
            ->assertSee('৳900.00')
            ->assertSee('Sellers owe us')
            ->assertSee('৳380.00')
            ->assertSee('Owing Store')
            ->assertSee('profit before running costs')
            ->call('setPreset', 'yesterday')
            ->assertSee('৳0.00');

        $this->get(MoneyOverviewPage::getUrl())->assertOk();
    }

    public function test_the_seller_money_page_keeps_owed_and_owing_apart(): void
    {
        $summary = app(SellerMoneyService::class)->summary($this->owing->id);
        $this->assertSame(0.0, $summary['owed_to_them']['total']);
        $this->assertSame(380.0, $summary['they_owe_us']['total']);

        $timeline = app(SellerMoneyService::class)->timeline($this->owed->id);
        $this->assertCount(2, $timeline);
        $this->assertTrue($timeline[0]['moved']);
        $this->assertSame(900.0, $timeline[0]['balance_after']);
        $this->assertSame(900.0, $timeline[1]['change']);

        $this->get(SellerMoneyPage::getUrl(['seller' => $this->owed->id]))
            ->assertOk()
            ->assertSee('Money: Owed Store')
            ->assertSee('Released after the 48-hour check');

        $this->get(SellerMoneyPage::getUrl(['seller' => $this->owing->id]))
            ->assertOk()
            ->assertSee('Overdue since')
            ->assertSee('Already counted in their balance below zero');
    }

    public function test_sellers_and_buyers_cannot_open_the_money_pages(): void
    {
        $this->actingAs($this->owed);

        $this->get(MoneyOverviewPage::getUrl())->assertForbidden();
        $this->get(SellerMoneyPage::getUrl(['seller' => $this->owed->id]))->assertForbidden();
    }
}
