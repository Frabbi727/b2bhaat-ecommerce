<?php

declare(strict_types=1);

namespace App\Services\Accounting\EventPipeline;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Exceptions\Accounting\MissingAccountException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalEntry;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\Schema;

class MonetizationJournalHandler
{
    public function __construct(
        protected ?JournalPostingService $journalPostingService = null,
    ) {
        $this->journalPostingService ??= app(JournalPostingService::class);
    }

    /**
     * Record accounting entry for a successful seller payment based on invoice type.
     */
    public function recordSellerPayment(SellerPayment $payment): ?AccountingJournalEntry
    {
        if ($payment->status !== SellerPayment::STATUS_SUCCESSFUL || (float) $payment->amount <= 0.0) {
            return null;
        }

        $payment->loadMissing('invoice');
        $invoice = $payment->invoice;

        if (! $invoice) {
            return null;
        }

        return match ($invoice->invoice_type) {
            SellerInvoice::TYPE_SUBSCRIPTION_FEE => $this->recordSubscriptionPayment($payment),
            SellerInvoice::TYPE_LISTING_PACK => $this->recordListingPackPayment($payment),
            default => null,
        };
    }

    /**
     * Record subscription revenue journal entry.
     */
    public function recordSubscriptionPayment(SellerPayment $payment): ?AccountingJournalEntry
    {
        return $this->postMonetizationPayment(
            payment: $payment,
            event: AccountingEvent::SUBSCRIPTION_PAID,
            creditAccountSlug: 'subscription_revenue',
        );
    }

    /**
     * Record listing pack add-on revenue journal entry.
     */
    public function recordListingPackPayment(SellerPayment $payment): ?AccountingJournalEntry
    {
        return $this->postMonetizationPayment(
            payment: $payment,
            event: AccountingEvent::LISTING_PACK_PAID,
            creditAccountSlug: 'listing_pack_revenue',
        );
    }

    /**
     * Post a balanced double-entry monetization journal.
     */
    protected function postMonetizationPayment(
        SellerPayment $payment,
        AccountingEvent $event,
        string $creditAccountSlug,
    ): ?AccountingJournalEntry {
        if ($payment->status !== SellerPayment::STATUS_SUCCESSFUL) {
            return null;
        }

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0.0) {
            return null;
        }

        $debitAccountSlug = match ($payment->payment_method) {
            SellerPayment::METHOD_WALLET_BALANCE => 'seller_wallet_payable',
            SellerPayment::METHOD_BANK_WIRE => 'bank_operating',
            SellerPayment::METHOD_ADMIN_WAIVER => 'promotional_discount_expense',
            default => 'gateway_clearing',
        };

        // ACC-6: a missing account fails loudly so the payment rolls back, instead of skipping the journal.
        $missing = array_values(array_diff(
            [$creditAccountSlug, $debitAccountSlug],
            Schema::hasTable('accounting_accounts')
                ? AccountingAccount::whereIn('system_slug', [$creditAccountSlug, $debitAccountSlug])->pluck('system_slug')->all()
                : [],
        ));
        if ($missing !== []) {
            throw MissingAccountException::forSlugs($missing);
        }

        $payment->loadMissing('invoice');
        $invoiceNumber = $payment->invoice?->invoice_number ?? "SINV-{$payment->invoice_id}";
        $paymentMethod = $payment->payment_method ?? 'unknown';

        $debitNarration = match ($payment->payment_method) {
            SellerPayment::METHOD_WALLET_BALANCE => "Wallet balance deduction for {$invoiceNumber}",
            SellerPayment::METHOD_BANK_WIRE => "Bank wire transfer received for {$invoiceNumber}",
            SellerPayment::METHOD_ADMIN_WAIVER => "Platform admin waiver applied for {$invoiceNumber}",
            default => "Gateway payment ({$paymentMethod}) received for {$invoiceNumber}",
        };

        $creditNarration = match ($event) {
            AccountingEvent::SUBSCRIPTION_PAID => "Subscription revenue recognized for {$invoiceNumber}",
            AccountingEvent::LISTING_PACK_PAID => "Listing pack revenue recognized for {$invoiceNumber}",
            default => "Monetization revenue recognized for {$invoiceNumber}",
        };

        $lines = [
            new JournalLineData(
                accountSlug: $debitAccountSlug,
                debit: $amount,
                credit: 0.00,
                sellerId: $payment->payment_method === SellerPayment::METHOD_WALLET_BALANCE ? $payment->seller_id : null,
                narration: $debitNarration,
            ),
            new JournalLineData(
                accountSlug: $creditAccountSlug,
                debit: 0.00,
                credit: $amount,
                sellerId: $payment->seller_id,
                narration: $creditNarration,
            ),
        ];

        $description = match ($event) {
            AccountingEvent::SUBSCRIPTION_PAID => "Seller subscription payment for invoice {$invoiceNumber} via {$paymentMethod}",
            AccountingEvent::LISTING_PACK_PAID => "Seller listing pack payment for invoice {$invoiceNumber} via {$paymentMethod}",
            default => "Seller monetization payment for invoice {$invoiceNumber} via {$paymentMethod}",
        };

        $entryData = new JournalEntryData(
            entryDate: now()->toDateString(),
            financialEvent: $event->value,
            idempotencyKey: "seller_payment_{$payment->id}",
            description: $description,
            lines: $lines,
            referenceType: SellerPayment::class,
            referenceId: $payment->id,
            metadata: [
                'source_module' => 'monetization',
                'source_transaction_id' => (string) $payment->id,
                'invoice_id' => $payment->invoice_id,
                'invoice_number' => $invoiceNumber,
                'seller_id' => $payment->seller_id,
                'payment_method' => $payment->payment_method,
                'transaction_reference' => $payment->transaction_reference,
            ],
        );

        return $this->journalPostingService->post($entryData);
    }
}
