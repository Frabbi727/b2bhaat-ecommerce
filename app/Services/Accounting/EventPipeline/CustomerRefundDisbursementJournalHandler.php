<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Exceptions\Accounting\MissingAccountException;
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

        $this->assertAccountsConfigured($creditAccountSlug);

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
     * Every account this journal needs must exist; otherwise the posting fails loudly and the money movement around
     * it rolls back (ACC-6), instead of the journal being skipped.
     *
     * @throws MissingAccountException
     */
    protected function assertAccountsConfigured(string ...$slugs): void
    {
        $required = array_values(array_unique(array_merge(['customer_refund_payable'], $slugs)));
        $present = Schema::hasTable('accounting_accounts')
            ? AccountingAccount::whereIn('system_slug', $required)->pluck('system_slug')->all()
            : [];
        $missing = array_values(array_diff($required, $present));

        if ($missing !== []) {
            throw MissingAccountException::forSlugs($missing);
        }
    }
}
