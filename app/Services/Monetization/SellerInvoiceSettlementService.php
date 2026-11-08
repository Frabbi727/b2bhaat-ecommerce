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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One place that turns a seller's plan choice into an invoice, and a paid invoice into the right
 * subscription period. Wallet, offline and auto-renew payments all go through here so they behave
 * the same way (SEC-3, SEC-4, MON-1, MON-4, MON-5).
 */
class SellerInvoiceSettlementService
{
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
     * @param  array{payment_method: string, amount: float|int|string, transaction_reference: string, notes?: string|null}  $payment
     */
    public function recordOfflinePayment(User $seller, int $invoiceId, array $payment, User $admin): SellerInvoice
    {
        return DB::transaction(function () use ($seller, $invoiceId, $payment, $admin): SellerInvoice {
            $invoice = $this->lockUnpaidInvoice($seller, $invoiceId);

            SellerPayment::create([
                'invoice_id' => $invoice->id,
                'seller_id' => $seller->id,
                'amount' => (float) $payment['amount'],
                'payment_method' => $payment['payment_method'],
                'transaction_reference' => $payment['transaction_reference'],
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
                'ends_at' => now()->addDays($subscription->plan?->billing_cycle_days ?: 30),
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

        // Renewal: add the paid cycle on top of any days the seller still has left.
        $periodStart = $subscription->ends_at && $subscription->ends_at->isFuture() ? $subscription->ends_at : now();
        $subscription->update([
            'status' => SellerSubscription::STATUS_ACTIVE,
            'ends_at' => $periodStart->copy()->addDays($subscription->plan?->billing_cycle_days ?: 30),
            'grace_period_ends_at' => null,
        ]);
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

        SellerInvoice::whereIn('subscription_id', $pendingIds)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->update(['status' => SellerInvoice::STATUS_VOID]);

        SellerSubscription::whereIn('id', $pendingIds)->update(['status' => SellerSubscription::STATUS_CANCELLED]);
    }
}
