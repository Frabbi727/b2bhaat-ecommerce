<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\Refund;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\Schema;

class CustomerRefundDisbursementJournalHandler
{
    public function __construct(
        protected ?JournalPostingService $journalPostingService = null,
    ) {
        $this->journalPostingService ??= app(JournalPostingService::class);
    }

    /**
     * Record accounting entry for a disbursed customer refund.
     */
    public function recordRefundDisbursed(Refund $refund): ?AccountingJournalEntry
    {
        $amount = round((float) $refund->amount, 2);
        if ($amount <= 0.0 || $refund->status !== Refund::STATUS_PAID) {
            return null;
        }

        $refund->loadMissing(['order', 'subOrder']);

        $method = strtolower(trim((string) $refund->method));
        $creditAccountSlug = match (true) {
            in_array($method, ['bkash', 'nagad', 'sslcommerz', 'mfs'], true) => 'gateway_clearing',
            in_array($method, ['bank', 'bank_transfer', 'bank_wire', 'wire'], true) => 'bank_operating',
            $method === 'cash' => 'cash_on_hand',
            default => 'gateway_clearing',
        };

        if (! $this->areAccountsConfigured($creditAccountSlug)) {
            return null;
        }

        $customerId = $refund->order?->customer_id;
        $orderNumber = $refund->order?->order_number ?? (string) $refund->order_id;

        $methodName = $refund->method ?: 'manual';

        $lines = [
            // Debit: customer_refund_payable (amount = $refund->amount, customerId: $customerId)
            new JournalLineData(
                accountSlug: 'customer_refund_payable',
                debit: $amount,
                credit: 0.00,
                customerId: $customerId,
                subOrderId: $refund->sub_order_id,
                orderId: $refund->order_id,
                narration: 'Customer refund payable disbursed',
            ),
            // Credit: $creditAccountSlug (amount = $refund->amount)
            new JournalLineData(
                accountSlug: $creditAccountSlug,
                debit: 0.00,
                credit: $amount,
                subOrderId: $refund->sub_order_id,
                orderId: $refund->order_id,
                narration: "Disbursed via {$methodName}",
            ),
        ];

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: AccountingEvent::CUSTOMER_REFUND_DISBURSED->value,
            idempotencyKey: "customer_refund_disbursed_{$refund->id}",
            description: "Customer refund disbursed for Order #{$orderNumber} via {$methodName}",
            lines: $lines,
            referenceType: Refund::class,
            referenceId: $refund->id,
            metadata: array_filter([
                'order_id' => $refund->order_id,
                'sub_order_id' => $refund->sub_order_id,
                'customer_id' => $customerId,
                'refund_method' => $refund->method,
                'reference' => $refund->reference,
            ]),
        );

        return $this->journalPostingService->post($entryData);
    }

    /**
     * Check if required accounting accounts exist in the chart of accounts.
     */
    protected function areAccountsConfigured(string ...$slugs): bool
    {
        if (! Schema::hasTable('accounting_accounts')) {
            return false;
        }

        $required = array_values(array_unique(array_merge(['customer_refund_payable'], $slugs)));

        return AccountingAccount::whereIn('system_slug', $required)->count() === count($required);
    }
}
