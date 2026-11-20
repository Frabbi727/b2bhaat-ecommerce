<?php

declare(strict_types=1);

namespace Tests\Feature\SellerAccounting;

use App\Models\SellerAccounting\SellerJournalEntry;
use App\Models\SellerAccounting\SellerJournalLine;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\SellerAccounting\SellerChartOfAccountsService as Chart;
use App\Services\SellerAccounting\SellerFinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SellerAccounting\Concerns\BuildsSellerBooksFixtures;
use Tests\TestCase;

class SellerBooksProjectorTest extends TestCase
{
    use BuildsSellerBooksFixtures;
    use RefreshDatabase;

    private EscrowLedgerService $escrow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSellerBooksPrerequisites();
        $this->escrow = app(EscrowLedgerService::class);
    }

    /**
     * 2 × ৳300 (cost ৳200) + 1 × ৳300 (cost ৳100); commission ৳90, so ৳810 is held for the seller.
     */
    private function standardOrder(User $seller, bool $selfDelivered = false, float $shipping = 0.0, string $payment = 'bkash'): SubOrder
    {
        $shirt = $this->makeProduct($seller, 'Cotton Shirt', 999);
        $cap = $this->makeProduct($seller, 'Cap', 999);

        return $this->makeSubOrder($seller, [
            ['product' => $shirt, 'quantity' => 2, 'unit_price' => 300, 'unit_cost' => 200],
            ['product' => $cap, 'quantity' => 1, 'unit_price' => 300, 'unit_cost' => 100],
        ], commission: 90, shipping: $shipping, selfDelivered: $selfDelivered, paymentMethod: $payment);
    }

    public function test_escrow_hold_books_the_sale_commission_and_cost_of_goods(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);

        $this->escrow->holdInEscrow($subOrder);

        $this->assertSame(810.0, $this->debitBalance($seller, Chart::B2BHAAT_ON_HOLD));
        $this->assertSame(-900.0, $this->debitBalance($seller, Chart::SALES));
        $this->assertSame(90.0, $this->debitBalance($seller, Chart::COMMISSION));
        $this->assertSame(500.0, $this->debitBalance($seller, Chart::COGS), 'Cost uses the cost copied onto each order line');
        $this->assertSame(-500.0, $this->debitBalance($seller, Chart::INVENTORY));
        $this->assertBooksMatchWallet($seller);
        $this->assertTrialBalanceBalances($seller);

        $pnl = app(SellerFinancialReportService::class)->profitAndLoss($seller->id, null, null);
        $this->assertSame(900.0, $pnl['net_sales']);
        $this->assertSame(400.0, $pnl['gross_profit']);
        $this->assertSame(310.0, $pnl['net_profit']);
    }

    public function test_cost_falls_back_to_the_current_buy_price_for_orders_without_a_copied_cost(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, 'Old Order Product', 120);
        $subOrder = $this->makeSubOrder($seller, [['product' => $product, 'quantity' => 3, 'unit_price' => 200, 'unit_cost' => null]], commission: 60);

        $this->escrow->holdInEscrow($subOrder);

        $this->assertSame(360.0, $this->debitBalance($seller, Chart::COGS));
    }

    public function test_release_and_payout_flow_moves_money_between_the_wallet_accounts(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);

        $this->escrow->holdInEscrow($subOrder);
        $this->escrow->releaseFromEscrow($subOrder);

        $this->assertSame(0.0, $this->debitBalance($seller, Chart::B2BHAAT_ON_HOLD));
        $this->assertSame(810.0, $this->debitBalance($seller, Chart::B2BHAAT_AVAILABLE));

        $payout = $this->escrow->requestPayout($seller, 600);
        $this->assertSame(600.0, $this->debitBalance($seller, Chart::PAYOUT_IN_TRANSIT));
        $this->assertSame(210.0, $this->debitBalance($seller, Chart::B2BHAAT_AVAILABLE));
        $this->assertBooksMatchWallet($seller);

        $this->escrow->disbursePayout($payout, 'TRX-BOOKS-1', 'bkash');

        $this->assertSame(0.0, $this->debitBalance($seller, Chart::PAYOUT_IN_TRANSIT));
        $this->assertSame(600.0, $this->debitBalance($seller, Chart::BANK));
        $this->assertBooksMatchWallet($seller);
        $this->assertTrialBalanceBalances($seller);
    }

    public function test_rejected_payout_returns_the_amount_to_the_available_balance(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);
        $this->escrow->holdInEscrow($subOrder);
        $this->escrow->releaseFromEscrow($subOrder);

        $payout = $this->escrow->requestPayout($seller, 700);
        $this->escrow->rejectPayout($payout, 'Wrong account number');

        $this->assertSame(0.0, $this->debitBalance($seller, Chart::PAYOUT_IN_TRANSIT));
        $this->assertSame(810.0, $this->debitBalance($seller, Chart::B2BHAAT_AVAILABLE));
        $this->assertBooksMatchWallet($seller);
    }

    public function test_full_return_after_release_undoes_the_sale_and_puts_stock_back(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);
        $this->escrow->holdInEscrow($subOrder);
        $this->escrow->releaseFromEscrow($subOrder);

        $this->escrow->reverseReturnedSubOrder($subOrder, 'Buyer returned everything');

        $this->assertSame(900.0, $this->debitBalance($seller, Chart::SALES_RETURNS));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::COMMISSION));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::COGS));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::INVENTORY));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::B2BHAAT_AVAILABLE));
        $this->assertBooksMatchWallet($seller);

        $pnl = app(SellerFinancialReportService::class)->profitAndLoss($seller->id, null, null);
        $this->assertSame(0.0, $pnl['net_profit']);
    }

    public function test_cancelled_prepaid_order_reverses_the_held_sale(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);
        $this->escrow->holdInEscrow($subOrder);

        $this->escrow->refundCancelledEscrow($subOrder, 'Buyer cancelled');

        $this->assertSame(0.0, $this->debitBalance($seller, Chart::B2BHAAT_ON_HOLD));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::INVENTORY));
        $this->assertSame(0.0, app(SellerFinancialReportService::class)->profitAndLoss($seller->id, null, null)['net_profit']);
        $this->assertBooksMatchWallet($seller);
    }

    public function test_partial_return_while_held_books_returned_items_per_product(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);
        $this->escrow->holdInEscrow($subOrder);

        $capLine = $subOrder->items->firstWhere('unit_cost', '100.00');
        $returnRequest = $this->makeReturn($subOrder, [['item' => $capLine, 'quantity' => 1, 'commission' => 30]]);

        $this->escrow->reversePartialReturn($subOrder, $returnRequest, [
            'seller' => 270.0, 'commission' => 30.0, 'discount' => 0.0, 'refund' => 300.0, 'courier_fee' => 0.0, 'completes' => false,
        ], 'Cap was damaged');

        $this->assertSame(540.0, $this->debitBalance($seller, Chart::B2BHAAT_ON_HOLD));
        $this->assertSame(300.0, $this->debitBalance($seller, Chart::SALES_RETURNS));
        $this->assertSame(60.0, $this->debitBalance($seller, Chart::COMMISSION));
        $this->assertSame(400.0, $this->debitBalance($seller, Chart::COGS));
        $this->assertBooksMatchWallet($seller);

        $byProduct = collect(app(SellerFinancialReportService::class)->productProfit($seller->id, null, null))->keyBy('title');
        $this->assertSame(0.0, $byProduct['Cap']['net_sales']);
        $this->assertSame(0.0, $byProduct['Cap']['profit']);
        $this->assertSame(600.0, $byProduct['Cotton Shirt']['net_sales']);
        $this->assertSame(140.0, $byProduct['Cotton Shirt']['profit'], '600 sales − 400 cost − 60 commission');

        // The rest is released, then the last items come back: everything nets to zero
        $this->escrow->releaseFromEscrow($subOrder);
        $shirtLine = $subOrder->items->firstWhere('unit_cost', '200.00');
        $lastReturn = $this->makeReturn($subOrder, [['item' => $shirtLine, 'quantity' => 2, 'commission' => 60]]);
        $this->escrow->reversePartialReturn($subOrder, $lastReturn, [
            'seller' => 540.0, 'commission' => 60.0, 'discount' => 0.0, 'refund' => 600.0, 'courier_fee' => 0.0, 'completes' => true,
        ], 'Rest returned');

        $this->assertSame(0.0, $this->debitBalance($seller, Chart::B2BHAAT_AVAILABLE));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::INVENTORY));
        $this->assertSame(0.0, app(SellerFinancialReportService::class)->profitAndLoss($seller->id, null, null)['net_profit']);
        $this->assertBooksMatchWallet($seller);
    }

    public function test_self_collected_cod_books_cash_and_the_commission_owed(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller, selfDelivered: true, shipping: 60, payment: 'cod');

        $this->escrow->settleSelfCollectedCod($subOrder);

        $this->assertSame(960.0, $this->debitBalance($seller, Chart::CASH));
        $this->assertSame(-90.0, $this->debitBalance($seller, Chart::B2BHAAT_AVAILABLE));
        $this->assertSame(-60.0, $this->debitBalance($seller, Chart::DELIVERY_INCOME));
        $this->assertSame(90.0, $this->debitBalance($seller, Chart::COMMISSION));
        $this->assertBooksMatchWallet($seller);
        $this->assertTrialBalanceBalances($seller);

        // Whole order returned: commission given back, buyer refunded from the balance
        $this->escrow->reverseReturnedSubOrder($subOrder, 'Returned at the door');

        $this->assertSame(0.0, $this->debitBalance($seller, Chart::REFUNDS_OWED));
        $this->assertSame(0.0, $this->debitBalance($seller, Chart::INVENTORY));
        $this->assertSame(0.0, app(SellerFinancialReportService::class)->profitAndLoss($seller->id, null, null)['net_profit']);
        $this->assertBooksMatchWallet($seller);
        $this->assertTrialBalanceBalances($seller);
    }

    public function test_self_collected_cod_partial_return_settles_the_buyer_refund(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller, selfDelivered: true, shipping: 60, payment: 'cod');
        $this->escrow->settleSelfCollectedCod($subOrder);

        $capLine = $subOrder->items->firstWhere('unit_cost', '100.00');
        $returnRequest = $this->makeReturn($subOrder, [['item' => $capLine, 'quantity' => 1, 'commission' => 30]]);
        $this->escrow->reversePartialReturn($subOrder, $returnRequest, [
            'seller' => 270.0, 'commission' => 30.0, 'discount' => 0.0, 'refund' => 300.0, 'courier_fee' => 0.0, 'completes' => false,
        ], 'Cap was damaged');

        $this->assertSame(0.0, $this->debitBalance($seller, Chart::REFUNDS_OWED), 'The buyer refund settles what the return made owing');
        $this->assertSame(300.0, $this->debitBalance($seller, Chart::SALES_RETURNS));
        $this->assertSame(60.0, $this->debitBalance($seller, Chart::COMMISSION));
        $this->assertSame(-400.0, $this->debitBalance($seller, Chart::INVENTORY));
        $this->assertBooksMatchWallet($seller);
        $this->assertTrialBalanceBalances($seller);
    }

    public function test_every_automatic_entry_balances_and_replaying_records_nothing_twice(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);
        $this->escrow->holdInEscrow($subOrder);
        $this->escrow->releaseFromEscrow($subOrder);
        $this->escrow->requestPayout($seller, 500);

        foreach (SellerJournalEntry::with('lines')->where('seller_id', $seller->id)->get() as $entry) {
            $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.001, "Entry {$entry->entry_number} does not balance");
        }

        $entries = SellerJournalEntry::count();
        $lines = SellerJournalLine::count();

        $this->artisan('seller-books:rebuild', ['--seller' => $seller->id])->assertSuccessful();
        $this->artisan('seller-books:rebuild')->assertSuccessful();

        $this->assertSame($entries, SellerJournalEntry::count());
        $this->assertSame($lines, SellerJournalLine::count());
    }

    public function test_rebuild_creates_books_for_wallet_history_recorded_before_the_books_existed(): void
    {
        $seller = $this->makeSeller();
        $subOrder = $this->standardOrder($seller);
        $this->escrow->holdInEscrow($subOrder);
        $this->escrow->releaseFromEscrow($subOrder);
        $payout = $this->escrow->requestPayout($seller, 500);
        $this->escrow->disbursePayout($payout, 'TRX-BOOKS-2', 'bkash');

        // As if this history happened before the seller books shipped
        SellerJournalLine::query()->delete();
        SellerJournalEntry::query()->delete();

        $this->artisan('seller-books:rebuild')->assertSuccessful();

        $this->assertSame(500.0, $this->debitBalance($seller, Chart::BANK));
        $this->assertSame(310.0, $this->debitBalance($seller, Chart::B2BHAAT_AVAILABLE));
        $this->assertBooksMatchWallet($seller);
        $this->assertTrialBalanceBalances($seller);
    }

    public function test_platform_fee_paid_from_balance_is_an_expense(): void
    {
        $seller = $this->makeSeller();
        $wallet = SellerWallet::create(['seller_id' => $seller->id, 'available_balance' => -250, 'escrow_held_balance' => 0]);

        WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => 'subscription_payment',
            'status' => 'disbursed',
            'amount' => 250,
            'running_balance' => -250,
            'reference_note' => 'Gold plan, November',
        ]);

        $this->assertSame(250.0, $this->debitBalance($seller, Chart::PLATFORM_FEES));
        $this->assertBooksMatchWallet($seller);
    }

    public function test_wallet_money_without_history_can_be_adopted_as_an_opening_balance(): void
    {
        $seller = $this->makeSeller();
        SellerWallet::create(['seller_id' => $seller->id, 'available_balance' => 15000, 'escrow_held_balance' => 4500]);

        $this->artisan('seller-books:rebuild')->assertFailed();

        $this->artisan('seller-books:rebuild', ['--adopt-wallet-differences' => true])->assertSuccessful();
        $this->artisan('seller-books:rebuild', ['--adopt-wallet-differences' => true])->assertSuccessful();

        $this->assertSame(-19500.0, $this->debitBalance($seller, Chart::OPENING_BALANCE), 'Adopted once, however often the command runs');
        $this->assertBooksMatchWallet($seller);
        $this->assertTrialBalanceBalances($seller);
    }
}
