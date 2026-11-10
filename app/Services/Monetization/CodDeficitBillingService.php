<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\SellerInvoice;
use App\Models\SellerWallet;
use App\Models\WalletTransaction;
use App\Services\Settings\StoreConfigurationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MON-11: a seller who collects cash on delivery themselves is charged the platform's commission from their wallet,
 * which can take it below zero. Once it has stayed below zero for the grace days, the seller gets one bill for what is
 * still owed. The bill is withdrawn if later earnings cover the shortfall before it is paid.
 */
class CodDeficitBillingService
{
    public function __construct(
        protected StoreConfigurationService $config,
    ) {}

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        if (! $this->config->isMonetizationEnabled()) {
            return ['raised' => 0, 'withdrawn' => 0];
        }

        $now ??= now();

        return [
            'raised' => $this->raiseDueBills($now),
            'withdrawn' => $this->withdrawCoveredBills(),
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

                SellerInvoice::create([
                    'invoice_number' => 'SINV-'.$now->format('Ymd').'-'.strtoupper(Str::random(6)),
                    'seller_id' => $wallet->seller_id,
                    'invoice_type' => SellerInvoice::TYPE_COD_COMMISSION_DEFICIT,
                    'subtotal' => $owed,
                    'total_amount' => $owed,
                    'status' => SellerInvoice::STATUS_UNPAID,
                    'due_date' => $now->copy()->addDays($graceDays)->toDateString(),
                    'metadata' => ['deficit_since' => $since->toIso8601String()],
                    'billing_period_key' => 'cod-deficit:'.$wallet->seller_id.':'.$now->format('Ymd'),
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
            ->each(function (SellerInvoice $invoice) use (&$withdrawn): void {
                $balance = (float) SellerWallet::where('seller_id', $invoice->seller_id)->value('available_balance');

                // One by one so each withdrawn bill leaves an audit entry
                if ($balance >= 0) {
                    $invoice->update(['status' => SellerInvoice::STATUS_VOID]);
                    $withdrawn++;
                }
            });

        return $withdrawn;
    }

    private function unpaidBill(int $sellerId): ?SellerInvoice
    {
        return SellerInvoice::where('seller_id', $sellerId)
            ->where('invoice_type', SellerInvoice::TYPE_COD_COMMISSION_DEFICIT)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->first();
    }
}
