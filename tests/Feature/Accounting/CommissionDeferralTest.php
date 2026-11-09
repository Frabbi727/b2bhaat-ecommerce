<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingJournalLine;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Accounting\EventPipeline\CommissionRecognitionJournalHandler;
use App\Services\Accounting\EventPipeline\OrderPaymentJournalHandler;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Logistics\CourierStatusService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACC-11: commission on a prepaid order is income only once the parcel is delivered.
 */
class CommissionDeferralTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private GeneralLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::factory()->create(['role_id' => Role::where('slug', 'seller')->value('id')]);
        $this->ledger = app(GeneralLedgerService::class);
    }

    private function makeSubOrder(string $methodSlug, string $paymentSlug): SubOrder
    {
        $number = 'ORD-CD-'.uniqid();
        $order = Order::create([
            'order_number' => $number,
            'customer_id' => User::where('email', 'customer@retail.com')->value('id'),
            'order_status_id' => OrderStatus::where('slug', 'confirmed')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', $paymentSlug)->value('id'),
            'payment_method_id' => PaymentMethod::where('slug', $methodSlug)->value('id'),
            'shipping_address' => ['city' => 'Dhaka', 'recipient_phone' => '8801711000000'],
            'total_amount' => 1070.00,
            'shipping_amount' => 70.00,
            'discount_amount' => 0.00,
        ]);

        return SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', 'out_for_delivery')->value('id'),
            'courier_name' => 'Steadfast Courier',
            'subtotal' => 1000.00,
            'commission_amount' => 100.00,
            'shipping_amount' => 70.00,
            'is_self_delivered' => false,
        ]);
    }

    private function payPrepaid(SubOrder $subOrder): void
    {
        app(OrderPaymentJournalHandler::class)->recordPrepaidPayment($subOrder->order);
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);
    }

    private function assertBooksBalance(): void
    {
        $lines = AccountingJournalLine::query();
        $this->assertSame(round((float) $lines->sum('debit'), 2), round((float) $lines->sum('credit'), 2));
    }

    public function test_a_prepaid_payment_holds_commission_as_not_yet_earned(): void
    {
        $subOrder = $this->makeSubOrder('bkash', 'paid');

        $this->payPrepaid($subOrder);

        $this->assertSame(100.0, $this->ledger->getBalanceForSlug('deferred_commission_revenue'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('commission_revenue'));
        $this->assertBooksBalance();
    }

    public function test_delivery_moves_the_commission_to_income_once(): void
    {
        $subOrder = $this->makeSubOrder('bkash', 'paid');
        $this->payPrepaid($subOrder);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');
        app(CommissionRecognitionJournalHandler::class)->recordCommissionEarned($subOrder->fresh());

        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('deferred_commission_revenue'));
        $this->assertSame(100.0, $this->ledger->getBalanceForSlug('commission_revenue'));
        $this->assertSame(1, AccountingJournalEntry::where('financial_event', 'commission_earned')->count());
        $this->assertBooksBalance();
    }

    public function test_cancelling_before_delivery_takes_the_commission_out_of_the_deferred_account(): void
    {
        $subOrder = $this->makeSubOrder('bkash', 'paid');
        $this->payPrepaid($subOrder);

        app(EscrowLedgerService::class)->refundCancelledEscrow($subOrder, 'Buyer cancelled');

        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('deferred_commission_revenue'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('commission_revenue'));
        $this->assertSame(1070.0, $this->ledger->getBalanceForSlug('customer_refund_payable'));
        $this->assertBooksBalance();
    }

    public function test_a_return_after_release_takes_the_commission_back_out_of_income(): void
    {
        $subOrder = $this->makeSubOrder('bkash', 'paid');
        $this->payPrepaid($subOrder);
        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');
        $escrow = app(EscrowLedgerService::class);
        $escrow->releaseFromEscrow($subOrder->fresh());

        $escrow->reverseReturnedSubOrder($subOrder->fresh(), 'Damaged on arrival');

        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('deferred_commission_revenue'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('commission_revenue'));
        $this->assertBooksBalance();
    }

    public function test_an_order_paid_before_the_change_keeps_its_booked_commission(): void
    {
        $subOrder = $this->makeSubOrder('bkash', 'paid');
        // The journal an order paid before ACC-11 has: commission booked straight to income.
        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: 'order_prepaid_received',
            idempotencyKey: "order_prepaid_received_{$subOrder->parent_order_id}",
            description: 'Legacy prepaid payment',
            lines: [
                new JournalLineData(accountSlug: 'gateway_clearing', debit: 1070.00, orderId: $subOrder->parent_order_id),
                new JournalLineData(accountSlug: 'seller_escrow_payable', credit: 900.00, sellerId: $this->seller->id, subOrderId: $subOrder->id),
                new JournalLineData(accountSlug: 'commission_revenue', credit: 100.00, sellerId: $this->seller->id, subOrderId: $subOrder->id),
                new JournalLineData(accountSlug: 'shipping_service_revenue', credit: 70.00, subOrderId: $subOrder->id),
            ],
        ));
        app(EscrowLedgerService::class)->holdInEscrow($subOrder);

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        $this->assertSame(0, AccountingJournalEntry::where('financial_event', 'commission_earned')->count());
        $this->assertSame(100.0, $this->ledger->getBalanceForSlug('commission_revenue'));

        app(EscrowLedgerService::class)->refundCancelledEscrow($subOrder->fresh(), 'Refused at the door');

        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('commission_revenue'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('deferred_commission_revenue'));
        $this->assertBooksBalance();
    }

    public function test_courier_cod_still_books_commission_on_delivery(): void
    {
        $subOrder = $this->makeSubOrder('cod', 'unpaid');

        app(CourierStatusService::class)->updateStatus($subOrder, 'delivered');

        $this->assertSame(100.0, $this->ledger->getBalanceForSlug('commission_revenue'));
        $this->assertSame(0.0, $this->ledger->getBalanceForSlug('deferred_commission_revenue'));
        $this->assertSame(0, AccountingJournalEntry::where('financial_event', 'commission_earned')->count());
    }
}
