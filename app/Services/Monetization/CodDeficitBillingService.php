<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\OrderNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MON-11: a seller who collects cash on delivery themselves is charged the platform's commission from their wallet,
 * which can take it below zero. Once it has stayed below zero for the grace days, the seller gets one bill for what is
 * still owed. The bill is withdrawn if later earnings cover the shortfall before it is paid.
 *
 * Owner decision (item 7): the bill is due after the same grace days. If it is still unpaid the monetization grace
 * days after its due date, the store is suspended (products hidden, payouts already blocked) until it is paid or
 * earnings cover it.
 */
class CodDeficitBillingService
{
    public function __construct(
        protected StoreConfigurationService $config,
        protected MonetizationSuspensionService $suspensions,
        protected OrderNotificationService $notifications,
    ) {}

    /**
     * @return array{raised: int, withdrawn: int, suspended: int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        if (! $this->config->isMonetizationEnabled()) {
            return ['raised' => 0, 'withdrawn' => 0, 'suspended' => 0];
        }

        $now ??= now();

        return [
            'raised' => $this->raiseDueBills($now),
            'withdrawn' => $this->withdrawCoveredBills(),
            'suspended' => $this->suspendOverdueStores($now),
        ];
    }

    /**
     * When the wallet last went below zero and has stayed there, or null when it is not below zero.
     */
    public function deficitStartedAt(SellerWallet $wallet): ?CarbonInterface
    {
        if ((float) $wallet->available_balance >= 0) {
            return null;
        }

        $lastNonNegativeId = WalletTransaction::where('seller_wallet_id', $wallet->id)
            ->where('running_balance', '>=', 0)
            ->max('id');

        return WalletTransaction::where('seller_wallet_id', $wallet->id)
            ->when($lastNonNegativeId, fn ($query) => $query->where('id', '>', $lastNonNegativeId))
            ->orderBy('id')
            ->value('created_at');
    }

    private function raiseDueBills(CarbonInterface $now): int
    {
        $graceDays = $this->config->getCodDeficitGraceDays();
        $raised = 0;

        SellerWallet::where('available_balance', '<', 0)->each(function (SellerWallet $wallet) use ($now, $graceDays, &$raised): void {
            $since = $this->deficitStartedAt($wallet);

            if (! $since || $since->greaterThan($now->copy()->subDays($graceDays))) {
                return;
            }

            DB::transaction(function () use ($wallet, $since, $now, $graceDays, &$raised): void {
                $wallet = SellerWallet::lockForUpdate()->find($wallet->id);
                $owed = round(-1 * (float) $wallet->available_balance, 2);

                if ($owed <= 0 || $this->unpaidBill($wallet->seller_id)) {
                    return;
                }

                // Bill number, due date and key use the Dhaka date the seller sees (review R-7)
                $today = $now->copy()->timezone(config('app.display_timezone'));

                SellerInvoice::create([
                    'invoice_number' => 'SINV-'.$today->format('Ymd').'-'.strtoupper(Str::random(6)),
                    'seller_id' => $wallet->seller_id,
                    'invoice_type' => SellerInvoice::TYPE_COD_COMMISSION_DEFICIT,
                    'subtotal' => $owed,
                    'total_amount' => $owed,
                    'status' => SellerInvoice::STATUS_UNPAID,
                    'due_date' => $today->copy()->addDays($graceDays)->toDateString(),
                    'metadata' => ['deficit_since' => $since->toIso8601String()],
                    'billing_period_key' => 'cod-deficit:'.$wallet->seller_id.':'.$today->format('Ymd'),
                ]);
                $raised++;
            });
        });

        return $raised;
    }

    private function withdrawCoveredBills(): int
    {
        $withdrawn = 0;

        SellerInvoice::where('invoice_type', SellerInvoice::TYPE_COD_COMMISSION_DEFICIT)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->pluck('id')
            ->each(function (int $invoiceId) use (&$withdrawn): void {
                // One by one under a lock, so each withdrawn bill leaves an audit entry and an approval made
                // meanwhile is never overwritten (review R-6)
                DB::transaction(function () use ($invoiceId, &$withdrawn): void {
                    $invoice = SellerInvoice::lockForUpdate()->find($invoiceId);
                    if (! $invoice || $invoice->status !== SellerInvoice::STATUS_UNPAID) {
                        return;
                    }

                    // A payment the seller already sent is checked first; withdrawing the bill would leave it
                    // credited nowhere
                    if (SellerPayment::where('invoice_id', $invoice->id)->where('status', SellerPayment::STATUS_PENDING_VERIFICATION)->exists()) {
                        return;
                    }

                    $balance = (float) SellerWallet::where('seller_id', $invoice->seller_id)->value('available_balance');
                    if ($balance >= 0) {
                        $invoice->update(['status' => SellerInvoice::STATUS_VOID]);
                        $withdrawn++;

                        // A store suspended over this bill comes back once nothing else is owed
                        if ($seller = User::find($invoice->seller_id)) {
                            $this->suspensions->restoreAfterPayment($seller);
                        }
                    }
                });
            });

        return $withdrawn;
    }

    /**
     * Suspend the stores whose deficit bill is still unpaid more than the grace days after its due date. A bill with
     * a payment waiting to be checked is left alone, and a store already suspended is not suspended again.
     */
    private function suspendOverdueStores(CarbonInterface $now): int
    {
        $lastDueDate = $now->copy()->timezone(config('app.display_timezone'))->startOfDay()
            ->subDays($this->config->getMonetizationGracePeriodDays())
            ->toDateString();
        $suspended = 0;

        SellerInvoice::with('seller.sellerProfile')
            ->where('invoice_type', SellerInvoice::TYPE_COD_COMMISSION_DEFICIT)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->whereDate('due_date', '<', $lastDueDate)
            ->whereDoesntHave('payments', fn ($query) => $query->where('status', SellerPayment::STATUS_PENDING_VERIFICATION))
            ->get()
            ->each(function (SellerInvoice $invoice) use (&$suspended): void {
                $seller = $invoice->seller;
                if (! $seller || ! $seller->sellerProfile || $seller->sellerProfile->monetization_status === 'suspended') {
                    return;
                }

                $reason = "Commission owed on cash you collected (bill {$invoice->invoice_number}) is unpaid past its due date";
                $this->suspensions->suspendSeller($seller, $reason);
                $this->notifications->sendAccountSuspendedNotice($seller, $reason);
                $suspended++;
            });

        return $suspended;
    }

    private function unpaidBill(int $sellerId): ?SellerInvoice
    {
        return SellerInvoice::where('seller_id', $sellerId)
            ->where('invoice_type', SellerInvoice::TYPE_COD_COMMISSION_DEFICIT)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->first();
    }
}
