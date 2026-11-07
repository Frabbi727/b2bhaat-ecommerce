<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Order;
use App\Services\Accounting\JournalPostingService;
use Carbon\Carbon;
use Database\Seeders\AccountingChartOfAccountsSeeder;

class OrderPaymentJournalHandler
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
    ) {}

    public function recordPrepaidPayment(Order $order): AccountingJournalEntry
    {
        if (AccountingAccount::count() === 0) {
            (new AccountingChartOfAccountsSeeder)->run();
        }

        $entryDate = $order->created_at ? $order->created_at->toDateString() : now()->toDateString();
        $this->ensureActivePeriod($entryDate);

        $lines = [];

        // 1. Debit Payment Gateway Clearing for cash received
        $lines[] = new JournalLineData(
            accountSlug: 'gateway_clearing',
            debit: (float) $order->total_amount,
            credit: 0.00,
            customerId: $order->customer_id,
            orderId: $order->id,
            narration: "Prepaid customer payment for Order #{$order->order_number}",
        );

        // 2. Debit Platform Promotional Discount Expense if discount applied
        if ((float) $order->discount_amount > 0) {
            $lines[] = new JournalLineData(
                accountSlug: 'promotional_discount_expense',
                debit: (float) $order->discount_amount,
                credit: 0.00,
                customerId: $order->customer_id,
                orderId: $order->id,
                narration: "Platform promotional discount for Order #{$order->order_number}",
            );
        }

        // 3. Credit Seller Escrow, Platform Commission, and Delivery Clearing for each sub-order
        $order->loadMissing('subOrders');
        foreach ($order->subOrders as $subOrder) {
            // Net payable to seller held in escrow
            $lines[] = new JournalLineData(
                accountSlug: 'seller_escrow_payable',
                debit: 0.00,
                credit: (float) $subOrder->net_earnings,
                sellerId: $subOrder->seller_id,
                subOrderId: $subOrder->id,
                orderId: $order->id,
                narration: "Seller net escrow for Sub-Order #{$subOrder->sub_order_number}",
            );

            // Earned platform commission
            if ((float) $subOrder->commission_amount > 0) {
                $lines[] = new JournalLineData(
                    accountSlug: 'commission_revenue',
                    debit: 0.00,
                    credit: (float) $subOrder->commission_amount,
                    sellerId: $subOrder->seller_id,
                    subOrderId: $subOrder->id,
                    orderId: $order->id,
                    narration: "Commission revenue for Sub-Order #{$subOrder->sub_order_number}",
                );
            }

            // Shipping fee (if not self-delivered by seller)
            if ((float) $subOrder->shipping_amount > 0) {
                if ($subOrder->is_self_delivered) {
                    // Self-delivered shipping goes to seller escrow
                    $lines[] = new JournalLineData(
                        accountSlug: 'seller_escrow_payable',
                        debit: 0.00,
                        credit: (float) $subOrder->shipping_amount,
                        sellerId: $subOrder->seller_id,
                        subOrderId: $subOrder->id,
                        orderId: $order->id,
                        narration: "Self-delivery shipping fee to seller for Sub-Order #{$subOrder->sub_order_number}",
                    );
                } else {
                    $lines[] = new JournalLineData(
                        accountSlug: 'courier_delivery_expense',
                        debit: 0.00,
                        credit: (float) $subOrder->shipping_amount,
                        subOrderId: $subOrder->id,
                        orderId: $order->id,
                        narration: "Courier shipping fee for Sub-Order #{$subOrder->sub_order_number}",
                    );
                }
            }
        }

        $entryData = new JournalEntryData(
            entryDate: $order->created_at ? $order->created_at->toDateString() : now()->toDateString(),
            financialEvent: AccountingEvent::ORDER_PREPAID_RECEIVED->value,
            idempotencyKey: "order_prepaid_received_{$order->id}",
            description: "Online prepaid payment verified for Order #{$order->order_number}",
            lines: $lines,
            referenceType: Order::class,
            referenceId: $order->id,
        );

        return $this->journalPostingService->post($entryData);
    }

    protected function ensureActivePeriod(string $date): void
    {
        $period = AccountingPeriod::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if ($period !== null) {
            return;
        }

        $carbonDate = Carbon::parse($date);
        $yearStart = $carbonDate->month >= 7
            ? Carbon::create($carbonDate->year, 7, 1)->toDateString()
            : Carbon::create($carbonDate->year - 1, 7, 1)->toDateString();
        $yearEnd = $carbonDate->month >= 7
            ? Carbon::create($carbonDate->year + 1, 6, 30)->toDateString()
            : Carbon::create($carbonDate->year, 6, 30)->toDateString();

        $fiscalYear = AccountingFiscalYear::firstOrCreate(
            ['start_date' => $yearStart, 'end_date' => $yearEnd],
            [
                'name' => 'FY '.Carbon::parse($yearStart)->year.'-'.Carbon::parse($yearEnd)->year,
                'is_closed' => false,
            ]
        );

        $periodStart = $carbonDate->copy()->startOfMonth()->toDateString();
        $periodEnd = $carbonDate->copy()->endOfMonth()->toDateString();

        AccountingPeriod::firstOrCreate(
            ['start_date' => $periodStart, 'end_date' => $periodEnd],
            [
                'fiscal_year_id' => $fiscalYear->id,
                'period_number' => $carbonDate->month,
                'name' => $carbonDate->format('F Y'),
                'is_closed' => false,
            ]
        );
    }
}
