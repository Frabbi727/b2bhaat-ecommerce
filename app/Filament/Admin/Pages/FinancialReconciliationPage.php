<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\GeneralLedgerService;
use App\Services\Audit\AuditService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class FinancialReconciliationPage extends Page
{
    protected static UnitEnum|string|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?string $title = 'Financial Reconciliation';

    protected static ?string $slug = 'financial-reconciliation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected string $view = 'filament.admin.pages.financial-reconciliation-page';

    public bool $showOnlyDiscrepancies = false;

    public string $search = '';

    public string $sortBy = 'discrepancy_desc';

    public ?string $lastAuditedAt = null;

    protected ?array $cachedMetrics = null;

    /**
     * @var array{total_sellers: int, reconciled_count: int, unreconciled_count: int, details: list<array{is_reconciled: bool, wallet_balance: float, ledger_balance: float, discrepancy: float, escrow_wallet_balance: float, escrow_ledger_balance: float, escrow_discrepancy: float, reserved_wallet_balance: float, reserved_ledger_balance: float, reserved_discrepancy: float, seller_id: int}>, sellers_by_id: Collection<int, User>}|null
     */
    protected ?array $cachedSellersRawAudit = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && in_array($user->role?->slug, ['super_admin', 'admin'], true));
    }

    public function mount(): void
    {
        $this->lastAuditedAt = Carbon::now()->toDateTimeString();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export Audit CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action('exportCsv'),

            Action::make('runAudit')
                ->label('Run Full Audit')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->action('runAudit'),
        ];
    }

    /**
     * Run full financial audit action.
     */
    public function runAudit(): void
    {
        $this->lastAuditedAt = Carbon::now()->toDateTimeString();
        $this->cachedMetrics = null;
        $this->cachedSellersRawAudit = null;

        $metrics = $this->getReconciliationMetrics();
        $sellersData = $this->getSellersAuditData();

        $discrepancyCount = 0;
        if (! $metrics['seller_wallet']['is_reconciled']) {
            $discrepancyCount++;
        }
        if (! $metrics['seller_escrow']['is_reconciled']) {
            $discrepancyCount++;
        }
        if (! $metrics['reserved_payouts']['is_reconciled']) {
            $discrepancyCount++;
        }
        if (! $metrics['customer_refunds']['is_reconciled']) {
            $discrepancyCount++;
        }
        $discrepancyCount += $sellersData['unreconciled_count'];

        $body = $discrepancyCount === 0
            ? 'All sub-ledgers and general ledger liability balances are fully reconciled.'
            : "Audit completed with {$discrepancyCount} discrepancy item(s) detected.";

        $notification = Notification::make()
            ->title('Marketplace Financial Audit Complete')
            ->body($body);

        if ($discrepancyCount === 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();
    }

    /**
     * Export reconciliation audit details as CSV.
     */
    public function exportCsv(): StreamedResponse
    {
        $metrics = $this->getReconciliationMetrics();
        $sellersData = $this->getSellersAuditData();
        $dateLabel = Carbon::now()->format('Y-m-d');
        $filename = "financial-reconciliation-audit-{$dateLabel}.csv";

        \app(AuditService::class)->recordExport('financial reconciliation report', $filename, null);

        return response()->streamDownload(function () use ($metrics, $sellersData): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['B2Bhaat Marketplace — Financial Reconciliation Audit Statement']);
            fputcsv($handle, ['Audited At:', Carbon::now()->toDateTimeString()]);
            fputcsv($handle, ['Currency:', 'Bangladeshi Taka (BDT / ৳)']);
            fputcsv($handle, []);

            // Summary Metrics
            fputcsv($handle, ['CORE RECONCILIATION SUBSYSTEMS']);
            fputcsv($handle, ['Metric Name', 'Sub-Ledger Total (BDT)', 'General Ledger Total (BDT)', 'Variance (BDT)', 'Status']);
            foreach ($metrics as $metric) {
                fputcsv($handle, [
                    $metric['title'],
                    number_format($metric['sub_ledger_total'], 2, '.', ''),
                    number_format($metric['gl_total'], 2, '.', ''),
                    number_format($metric['discrepancy'], 2, '.', ''),
                    $metric['is_reconciled'] ? 'RECONCILED' : 'DISCREPANCY',
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['SELLER SUB-LEDGER RECONCILIATION BREAKDOWN']);
            fputcsv($handle, [
                'Seller ID',
                'Seller Name',
                'Seller Email',
                'Wallet Avail (Subledger)',
                'GL Wallet Payable',
                'Wallet Variance',
                'Escrow Held (Subledger)',
                'GL Escrow Payable',
                'Escrow Variance',
                'Reserved Payout (Subledger)',
                'GL Reserved Payable',
                'Reserved Variance',
                'Net Discrepancy',
                'Status',
            ]);

            foreach ($sellersData['items'] as $item) {
                fputcsv($handle, [
                    $item['seller_id'],
                    $item['seller_name'],
                    $item['seller_email'],
                    number_format($item['wallet_balance'], 2, '.', ''),
                    number_format($item['ledger_balance'], 2, '.', ''),
                    number_format($item['discrepancy'], 2, '.', ''),
                    number_format($item['escrow_wallet_balance'], 2, '.', ''),
                    number_format($item['escrow_ledger_balance'], 2, '.', ''),
                    number_format($item['escrow_discrepancy'], 2, '.', ''),
                    number_format($item['reserved_wallet_balance'], 2, '.', ''),
                    number_format($item['reserved_ledger_balance'], 2, '.', ''),
                    number_format($item['reserved_discrepancy'], 2, '.', ''),
                    number_format($item['net_discrepancy'], 2, '.', ''),
                    $item['is_reconciled'] ? 'RECONCILED' : 'DISCREPANCY',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Compile the 4 core reconciliation metrics.
     *
     * @return array{
     *     seller_wallet: array{
     *         title: string,
     *         sub_ledger_total: float,
     *         gl_total: float,
     *         discrepancy: float,
     *         is_reconciled: bool
     *     },
     *     seller_escrow: array{
     *         title: string,
     *         sub_ledger_total: float,
     *         gl_total: float,
     *         discrepancy: float,
     *         is_reconciled: bool
     *     },
     *     reserved_payouts: array{
     *         title: string,
     *         sub_ledger_total: float,
     *         gl_total: float,
     *         discrepancy: float,
     *         is_reconciled: bool
     *     },
     *     customer_refunds: array{
     *         title: string,
     *         sub_ledger_total: float,
     *         gl_total: float,
     *         discrepancy: float,
     *         is_reconciled: bool
     *     }
     * }
     */
    public function getReconciliationMetrics(): array
    {
        if ($this->cachedMetrics !== null) {
            return $this->cachedMetrics;
        }

        /** @var GeneralLedgerService $glService */
        $glService = app(GeneralLedgerService::class);
        /** @var AccountingReconciliationService $reconciliationService */
        $reconciliationService = app(AccountingReconciliationService::class);

        // 1. Seller Wallet Payables
        $walletSubTotal = round((float) SellerWallet::sum('available_balance'), 2);
        $walletGlTotal = round($glService->getBalanceForSlug('seller_wallet_payable'), 2);
        $walletDiscrepancy = round($walletSubTotal - $walletGlTotal, 2);
        $walletIsReconciled = abs($walletDiscrepancy) < 0.001;
        if ($walletIsReconciled) {
            $walletDiscrepancy = 0.00;
        }

        // 2. Seller Escrow Payables
        $escrowSubTotal = round((float) SellerWallet::sum('escrow_held_balance'), 2);
        $escrowGlTotal = round($glService->getBalanceForSlug('seller_escrow_payable'), 2);
        $escrowDiscrepancy = round($escrowSubTotal - $escrowGlTotal, 2);
        $escrowIsReconciled = abs($escrowDiscrepancy) < 0.001;
        if ($escrowIsReconciled) {
            $escrowDiscrepancy = 0.00;
        }

        // 3. Reserved Payout Payables
        $reservedResult = $reconciliationService->verifyReservedPayouts();
        $reservedSubTotal = $reservedResult['total_pending_payouts'];
        $reservedGlTotal = $reservedResult['ledger_reserved_balance'];
        $reservedDiscrepancy = $reservedResult['discrepancy'];
        $reservedIsReconciled = $reservedResult['is_reconciled'];

        // 4. Customer Refund Payables
        $refundResult = $reconciliationService->verifyCustomerRefundPayables();
        $refundSubTotal = $refundResult['total_pending_refunds'];
        $refundGlTotal = $refundResult['ledger_refund_payable_balance'];
        $refundDiscrepancy = $refundResult['discrepancy'];
        $refundIsReconciled = $refundResult['is_reconciled'];

        return $this->cachedMetrics = [
            'seller_wallet' => [
                'title' => 'Seller Wallet Payables',
                'sub_ledger_total' => $walletSubTotal,
                'gl_total' => $walletGlTotal,
                'discrepancy' => $walletDiscrepancy,
                'is_reconciled' => $walletIsReconciled,
            ],
            'seller_escrow' => [
                'title' => 'Seller Escrow Payables',
                'sub_ledger_total' => $escrowSubTotal,
                'gl_total' => $escrowGlTotal,
                'discrepancy' => $escrowDiscrepancy,
                'is_reconciled' => $escrowIsReconciled,
            ],
            'reserved_payouts' => [
                'title' => 'Reserved Payout Payables',
                'sub_ledger_total' => $reservedSubTotal,
                'gl_total' => $reservedGlTotal,
                'discrepancy' => $reservedDiscrepancy,
                'is_reconciled' => $reservedIsReconciled,
            ],
            'customer_refunds' => [
                'title' => 'Customer Refund Payables',
                'sub_ledger_total' => $refundSubTotal,
                'gl_total' => $refundGlTotal,
                'discrepancy' => $refundDiscrepancy,
                'is_reconciled' => $refundIsReconciled,
            ],
        ];
    }

    /**
     * Get sellers audit data with optional search, sorting, and filtering for discrepancies only.
     *
     * @return array{
     *     total_sellers: int,
     *     reconciled_count: int,
     *     unreconciled_count: int,
     *     items: list<array{
     *         seller_id: int,
     *         seller_name: string,
     *         seller_email: string,
     *         wallet_balance: float,
     *         ledger_balance: float,
     *         discrepancy: float,
     *         escrow_wallet_balance: float,
     *         escrow_ledger_balance: float,
     *         escrow_discrepancy: float,
     *         reserved_wallet_balance: float,
     *         reserved_ledger_balance: float,
     *         reserved_discrepancy: float,
     *         net_discrepancy: float,
     *         is_reconciled: bool
     *     }>
     * }
     */
    public function getSellersAuditData(): array
    {
        if ($this->cachedSellersRawAudit === null) {
            /** @var AccountingReconciliationService $reconciliationService */
            $reconciliationService = app(AccountingReconciliationService::class);
            $sellersAudit = $reconciliationService->verifyAllSellers();

            // Load seller records to display names and emails
            $sellerIds = array_column($sellersAudit['details'], 'seller_id');
            $sellersById = User::whereIn('id', $sellerIds)
                ->get(['id', 'name', 'email'])
                ->keyBy('id');

            $this->cachedSellersRawAudit = [
                'total_sellers' => $sellersAudit['total_sellers'],
                'reconciled_count' => $sellersAudit['reconciled_count'],
                'unreconciled_count' => $sellersAudit['unreconciled_count'],
                'details' => $sellersAudit['details'],
                'sellers_by_id' => $sellersById,
            ];
        }

        $raw = $this->cachedSellersRawAudit;
        $items = [];
        $trimmedSearch = mb_strtolower(trim($this->search));

        foreach ($raw['details'] as $detail) {
            $seller = $raw['sellers_by_id']->get($detail['seller_id']);
            $netDiscrepancy = round(
                abs($detail['discrepancy']) + abs($detail['escrow_discrepancy']) + abs($detail['reserved_discrepancy']),
                2
            );

            $item = [
                'seller_id' => $detail['seller_id'],
                'seller_name' => $seller?->name ?? 'Unknown Seller',
                'seller_email' => $seller?->email ?? 'N/A',
                'wallet_balance' => $detail['wallet_balance'],
                'ledger_balance' => $detail['ledger_balance'],
                'discrepancy' => $detail['discrepancy'],
                'escrow_wallet_balance' => $detail['escrow_wallet_balance'],
                'escrow_ledger_balance' => $detail['escrow_ledger_balance'],
                'escrow_discrepancy' => $detail['escrow_discrepancy'],
                'reserved_wallet_balance' => $detail['reserved_wallet_balance'],
                'reserved_ledger_balance' => $detail['reserved_ledger_balance'],
                'reserved_discrepancy' => $detail['reserved_discrepancy'],
                'net_discrepancy' => $netDiscrepancy,
                'is_reconciled' => $detail['is_reconciled'],
            ];

            if ($this->showOnlyDiscrepancies && $detail['is_reconciled']) {
                continue;
            }

            if ($trimmedSearch !== '') {
                $name = mb_strtolower($item['seller_name']);
                $email = mb_strtolower($item['seller_email']);
                $id = (string) $item['seller_id'];

                if (! str_contains($name, $trimmedSearch) && ! str_contains($email, $trimmedSearch) && ! str_contains($id, $trimmedSearch)) {
                    continue;
                }
            }

            $items[] = $item;
        }

        // Sorting
        match ($this->sortBy) {
            'discrepancy_desc' => usort($items, fn (array $a, array $b): int => $b['net_discrepancy'] <=> $a['net_discrepancy']),
            'name_asc' => usort($items, fn (array $a, array $b): int => strcasecmp($a['seller_name'], $b['seller_name'])),
            'wallet_desc' => usort($items, fn (array $a, array $b): int => $b['wallet_balance'] <=> $a['wallet_balance']),
            default => null,
        };

        return [
            'total_sellers' => $raw['total_sellers'],
            'reconciled_count' => $raw['reconciled_count'],
            'unreconciled_count' => $raw['unreconciled_count'],
            'items' => $items,
        ];
    }
}
