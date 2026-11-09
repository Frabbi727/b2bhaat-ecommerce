<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerPlan;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One place that turns a seller's plan choice into an invoice, and a paid invoice into the right
 * subscription period. Wallet, offline and auto-renew payments all go through here so they behave
 * the same way (SEC-3, SEC-4, MON-1, MON-4, MON-5).
 */
class SellerInvoiceSettlementService
{
    /** Subscription columns that hold the kept plan terms (MON-2). */
    public const TERM_COLUMNS = [
        'term_subscription_price',
        'term_billing_cycle_days',
        'term_is_listing_limit_enabled',
        'term_max_active_products',
        'term_is_commission_enabled',
        'term_retail_commission_rate',
        'term_wholesale_commission_rate',
    ];

    public function __construct(
        protected MonetizationSuspensionService $suspensionService
    ) {}

    /**
     * Raise an invoice for a plan change. The new plan waits as a "payment due" subscription and only
     * replaces the current one once the invoice is paid, so an abandoned change never affects the
     * current plan or triggers a grace period.
     */
    public function startPlanChange(User $seller, int $planId): SellerInvoice
    {
        $plan = SellerPlan::where('is_active', true)->find($planId);

        if (! $plan) {
            throw new DomainException('This plan is not available any more. Please choose another plan.');
        }

        $invoice = DB::transaction(function () use ($seller, $plan): SellerInvoice {
            // One plan change at a time per seller, even from two open tabs.
            User::whereKey($seller->id)->lockForUpdate()->first();
            $this->withdrawUnpaidPlanChanges($seller);

            $pendingSubscription = SellerSubscription::create([
                'seller_id' => $seller->id,
                'plan_id' => $plan->id,
                'status' => SellerSubscription::STATUS_PAYMENT_DUE,
                'starts_at' => now(),
                'ends_at' => now()->addDays($plan->billing_cycle_days ?: 30),
                'auto_renew' => true,
            ]);

            return SellerInvoice::create([
                'invoice_number' => 'SINV-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'seller_id' => $seller->id,
                'subscription_id' => $pendingSubscription->id,
                'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
                'subtotal' => (float) $plan->subscription_price,
                'total_amount' => (float) $plan->subscription_price,
                'status' => SellerInvoice::STATUS_UNPAID,
                'due_date' => now()->toDateString(),
                'metadata' => ['target_plan_id' => $plan->id, 'plan_name' => $plan->name],
                'billing_period_key' => SellerInvoice::planChangeKey($pendingSubscription->id),
            ]);
        });

        if ((float) $invoice->total_amount <= 0.00) {
            $this->settleUnpaidInvoice($invoice->id, $seller);
        }

        return $invoice->fresh();
    }

    /**
     * Pay an unpaid invoice from the seller's wallet. The invoice and wallet are locked, so two clicks
     * or two tabs can only ever take the money once.
     */
    public function payFromWallet(User $seller, int $invoiceId, string $referencePrefix = 'WALLET-OFFSET', ?string $note = null): SellerInvoice
    {
        return DB::transaction(function () use ($seller, $invoiceId, $referencePrefix, $note): SellerInvoice {
            $invoice = $this->lockUnpaidInvoice($seller, $invoiceId);
            $amount = (float) $invoice->total_amount;

            if ($amount > 0.00) {
                /** @var SellerWallet|null $wallet */
                $wallet = SellerWallet::where('seller_id', $seller->id)->lockForUpdate()->first();
                $available = (float) ($wallet?->available_balance ?? 0.00);

                if (! $wallet || $available < $amount) {
                    throw new DomainException('Not enough money in your wallet. This bill is ৳'.number_format($amount, 2).' and your wallet has ৳'.number_format($available, 2).'.');
                }

                $wallet->decrement('available_balance', $amount);
                $wallet->refresh();

                WalletTransaction::create([
                    'seller_wallet_id' => $wallet->id,
                    'transaction_type' => 'subscription_payment',
                    'status' => 'disbursed',
                    'amount' => $amount,
                    'running_balance' => (float) $wallet->available_balance,
                    'reference_note' => $note ?? "Platform billing fee settled for Invoice #{$invoice->invoice_number}",
                ]);

                SellerPayment::create([
                    'invoice_id' => $invoice->id,
                    'seller_id' => $seller->id,
                    'amount' => $amount,
                    'payment_method' => SellerPayment::METHOD_WALLET_BALANCE,
                    'transaction_reference' => "{$referencePrefix}-{$wallet->id}-{$invoice->id}",
                    'status' => SellerPayment::STATUS_SUCCESSFUL,
                    'verified_at' => now(),
                ]);
            }

            $this->applyPaidInvoice($invoice, $seller);

            return $invoice;
        });
    }

    /**
     * Record a payment an admin received outside the platform (bKash, Nagad, bank, waiver) against an
     * unpaid bill, then give the seller what the bill was for.
     *
     * The amount must equal the bill (no part-payments), and a bKash, Nagad or bank reference can pay only one bill (MON-6).
     *
     * @param  array{payment_method: string, amount?: float|int|string|null, transaction_reference?: string|null, notes?: string|null}  $payment
     */
    public function recordOfflinePayment(User $seller, int $invoiceId, array $payment, User $admin): SellerInvoice
    {
        // Two admins entering the same reference at once: the second waits, then sees it as already used.
        $lockKey = 'seller-payment-reference:'.md5((string) $payment['payment_method'].'|'.mb_strtolower(trim((string) ($payment['transaction_reference'] ?? ''))));

        try {
            return Cache::lock($lockKey, 15)->block(10, fn (): SellerInvoice => $this->recordOfflinePaymentLocked($seller, $invoiceId, $payment, $admin));
        } catch (LockTimeoutException) {
            // Shown to the admin like any other refusal instead of a server error (Wave 1 review M-5).
            throw new DomainException('A payment with this reference is being recorded by someone else right now. Wait a moment, then check the bill before trying again. Nothing was recorded.');
        }
    }

    /**
     * @param  array{payment_method: string, amount?: float|int|string|null, transaction_reference?: string|null, notes?: string|null}  $payment
     */
    private function recordOfflinePaymentLocked(User $seller, int $invoiceId, array $payment, User $admin): SellerInvoice
    {
        return DB::transaction(function () use ($seller, $invoiceId, $payment, $admin): SellerInvoice {
            $invoice = $this->lockUnpaidInvoice($seller, $invoiceId);
            $method = (string) $payment['payment_method'];
            $billTotal = round((float) $invoice->total_amount, 2);
            $isWaiver = $method === SellerPayment::METHOD_ADMIN_WAIVER;
            $reference = trim((string) ($payment['transaction_reference'] ?? ''));

            // MON-6: a bill is settled by one payment of exactly its amount; a waiver covers the whole bill.
            $amount = $isWaiver ? $billTotal : round((float) ($payment['amount'] ?? 0), 2);
            if (bccomp(sprintf('%.2f', $amount), sprintf('%.2f', $billTotal), 2) !== 0) {
                throw new DomainException(sprintf(
                    'Bill #%s is ৳%s, but ৳%s was entered. Record the full amount, or waive or reduce the bill first. Nothing was recorded.',
                    $invoice->invoice_number,
                    number_format($billTotal, 2),
                    number_format($amount, 2),
                ));
            }

            if (! $isWaiver) {
                if ($reference === '') {
                    throw new DomainException('Enter the TrxID or deposit slip number. Nothing was recorded.');
                }

                // MON-6: the same bKash, Nagad or bank reference can only pay one bill.
                $usedOn = SellerPayment::query()
                    ->where('payment_method', $method)
                    ->whereRaw('LOWER(TRIM(transaction_reference)) = ?', [mb_strtolower($reference)])
                    ->where('status', SellerPayment::STATUS_SUCCESSFUL)
                    ->with('invoice:id,invoice_number')
                    ->first();

                if ($usedOn) {
                    throw new DomainException(sprintf(
                        'Reference %s was already recorded for bill #%s. Check the reference; nothing was recorded.',
                        $reference,
                        $usedOn->invoice?->invoice_number ?? $usedOn->invoice_id,
                    ));
                }
            }

            SellerPayment::create([
                'invoice_id' => $invoice->id,
                'seller_id' => $seller->id,
                'amount' => $amount,
                'payment_method' => $method,
                'transaction_reference' => $reference !== '' ? $reference : null,
                'status' => SellerPayment::STATUS_SUCCESSFUL,
                'verified_by_user_id' => $admin->id,
                'verified_at' => now(),
                'notes' => $payment['notes'] ?? null,
            ]);

            $this->applyPaidInvoice($invoice, $seller, $admin);

            return $invoice;
        });
    }

    /**
     * Mark an unpaid invoice paid (payment already recorded elsewhere, or nothing to pay) and apply it.
     */
    public function settleUnpaidInvoice(int $invoiceId, User $seller, ?User $actor = null): SellerInvoice
    {
        return DB::transaction(function () use ($invoiceId, $seller, $actor): SellerInvoice {
            $invoice = $this->lockUnpaidInvoice($seller, $invoiceId);
            $this->applyPaidInvoice($invoice, $seller, $actor);

            return $invoice;
        });
    }

    /**
     * Lock the invoice row and refuse it unless it belongs to the seller and is still unpaid.
     */
    public function lockUnpaidInvoice(User $seller, int $invoiceId): SellerInvoice
    {
        $invoice = SellerInvoice::where('seller_id', $seller->id)->lockForUpdate()->find($invoiceId);

        if (! $invoice) {
            throw new DomainException('This bill could not be found.');
        }

        if ($invoice->status !== SellerInvoice::STATUS_UNPAID) {
            throw new DomainException("Bill #{$invoice->invoice_number} is already {$invoice->status}. Nothing was charged.");
        }

        return $invoice;
    }

    /**
     * Mark a locked invoice paid, give the seller the period they paid for, and lift any grace period
     * or suspension caused by the unpaid bill.
     */
    protected function applyPaidInvoice(SellerInvoice $invoice, User $seller, ?User $actor = null): void
    {
        $invoice->update([
            'status' => SellerInvoice::STATUS_PAID,
            'paid_at' => now(),
        ]);

        if ($invoice->invoice_type === SellerInvoice::TYPE_SUBSCRIPTION_FEE) {
            $this->applySubscriptionPeriod($invoice, $seller);
        }

        $this->suspensionService->restoreAfterPayment($seller, $actor);
    }

    protected function applySubscriptionPeriod(SellerInvoice $invoice, User $seller): void
    {
        $subscription = $invoice->subscription_id
            ? SellerSubscription::with('plan')->lockForUpdate()->find($invoice->subscription_id)
            : null;

        if ($subscription && $subscription->status === SellerSubscription::STATUS_PAYMENT_DUE) {
            $this->endCurrentSubscriptions($seller, $subscription->id);
            $subscription->update([
                'status' => SellerSubscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => now()->addDays($subscription->termBillingCycleDays() ?: 30),
                'grace_period_ends_at' => null,
            ]);

            return;
        }

        // Plan-change bills raised before this fix point at the old subscription and carry the new plan in metadata.
        $legacyTargetPlanId = $invoice->metadata['target_plan_id'] ?? null;
        $legacyTargetPlan = $legacyTargetPlanId ? SellerPlan::find($legacyTargetPlanId) : null;
        if ($legacyTargetPlan && $subscription?->plan_id !== $legacyTargetPlan->id) {
            $this->endCurrentSubscriptions($seller);
            SellerSubscription::create([
                'seller_id' => $seller->id,
                'plan_id' => $legacyTargetPlan->id,
                'status' => SellerSubscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => now()->addDays($legacyTargetPlan->billing_cycle_days ?: 30),
                'auto_renew' => true,
            ]);

            return;
        }

        if (! $subscription) {
            return;
        }

        if (in_array($subscription->status, [SellerSubscription::STATUS_EXPIRED, SellerSubscription::STATUS_CANCELLED], true)) {
            throw new DomainException("Bill #{$invoice->invoice_number} is for a plan that has already ended. Nothing was charged.");
        }

        // Renewal: the new period runs on the terms the renewal bill was raised on, or else the plan's current
        // terms (MON-2), and is added on top of any days the seller still has left.
        $renewalTerms = $invoice->metadata['renewal_terms'] ?? null;
        if (is_array($renewalTerms) && $renewalTerms !== []) {
            $subscription->forceFill(array_intersect_key($renewalTerms, array_flip(self::TERM_COLUMNS)) + ['terms_captured_at' => now()]);
        } elseif ($subscription->plan) {
            $subscription->forceFill(SellerSubscription::termsFromPlan($subscription->plan));
        }

        $periodStart = $subscription->ends_at && $subscription->ends_at->isFuture() ? $subscription->ends_at : now();
        $subscription->forceFill([
            'status' => SellerSubscription::STATUS_ACTIVE,
            'ends_at' => $periodStart->copy()->addDays($subscription->termBillingCycleDays() ?: 30),
            'grace_period_ends_at' => null,
        ])->save();
    }

    /**
     * Close every running subscription so the seller only ever has one live plan.
     */
    protected function endCurrentSubscriptions(User $seller, ?int $exceptSubscriptionId = null): void
    {
        SellerSubscription::where('seller_id', $seller->id)
            ->whereIn('status', [
                SellerSubscription::STATUS_ACTIVE,
                SellerSubscription::STATUS_GRACE_PERIOD,
                SellerSubscription::STATUS_SUSPENDED,
            ])
            ->when($exceptSubscriptionId, fn ($query) => $query->whereKeyNot($exceptSubscriptionId))
            ->get()
            ->each(function (SellerSubscription $subscription): void {
                // The plan is closed, so its unpaid renewal bill is no longer owed.
                SellerInvoice::where('subscription_id', $subscription->id)
                    ->where('status', SellerInvoice::STATUS_UNPAID)
                    ->update(['status' => SellerInvoice::STATUS_VOID]);

                $subscription->update([
                    'status' => SellerSubscription::STATUS_EXPIRED,
                    'ends_at' => $subscription->ends_at && $subscription->ends_at->isPast() ? $subscription->ends_at : now(),
                ]);
            });
    }

    /**
     * A seller who picks a plan again replaces any earlier plan change they never paid for.
     */
    protected function withdrawUnpaidPlanChanges(User $seller): void
    {
        $pendingIds = SellerSubscription::where('seller_id', $seller->id)
            ->where('status', SellerSubscription::STATUS_PAYMENT_DUE)
            ->pluck('id');

        if ($pendingIds->isEmpty()) {
            return;
        }

        // One by one, so each voided bill and cancelled subscription leaves an audit entry (AUD-4)
        SellerInvoice::whereIn('subscription_id', $pendingIds)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->get()
            ->each(fn (SellerInvoice $invoice) => $invoice->update(['status' => SellerInvoice::STATUS_VOID]));

        SellerSubscription::whereIn('id', $pendingIds)->get()
            ->each(fn (SellerSubscription $subscription) => $subscription->update(['status' => SellerSubscription::STATUS_CANCELLED]));
    }
}
