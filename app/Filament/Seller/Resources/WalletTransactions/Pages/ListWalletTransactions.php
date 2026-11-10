<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\WalletTransactions\Pages;

use App\Filament\Seller\Resources\WalletTransactions\Tables\WalletTransactionsTable;
use App\Filament\Seller\Resources\WalletTransactions\WalletTransactionResource;
use App\Filament\Seller\Resources\WalletTransactions\Widgets\SellerStatementOverviewWidget;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Audit\AuditService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    /**
     * @return array<Action>
     */
    public function getHeaderActions(): array
    {
        return [
            Action::make('printStatement')
                ->label('Print Statement')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('primary')
                ->url(fn (): string => route('seller.wallet.statement.print'))
                ->openUrlInNewTab(),

            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action('exportCsv'),
        ];
    }

    /**
     * Export statement transactions to CSV strictly scoped to the authenticated seller.
     */
    public function exportCsv(): StreamedResponse
    {
        /** @var User|null $seller */
        $seller = auth()->user();

        if (! $seller instanceof User) {
            abort(403, 'Unauthorized.');
        }

        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );

        $storeSlug = $seller->sellerProfile?->store_slug ?? 'merchant';
        $filename = "statement-{$storeSlug}-".now()->format('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        \app(AuditService::class)->recordExport('wallet statement', $filename, auth()->id());

        return response()->streamDownload(function () use ($seller, $wallet): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Metadata headers
            fputcsv($handle, ['B2Bhaat Marketplace — Merchant Account Statement']);
            fputcsv($handle, ['Merchant:', $seller->sellerProfile?->store_name ?? $seller->name, 'Phone:', $seller->phone ?? 'N/A']);
            fputcsv($handle, ['Trade License:', $seller->trade_license_number ?? 'N/A', 'Generated At:', now()->toDateTimeString()]);
            fputcsv($handle, ['Available Balance (BDT):', number_format((float) $wallet->available_balance, 2), 'On Hold After Delivery (BDT):', number_format((float) $wallet->escrow_held_balance, 2)]);
            fputcsv($handle, []);

            // Column headers
            fputcsv($handle, [
                'Transaction ID',
                'Date & Time',
                'Type',
                'Order #',
                'Amount (BDT)',
                'Running Balance (BDT)',
                'Status',
                'Reference Note',
            ]);

            foreach ($wallet->transactions()->with('subOrder')->latest('created_at')->orderByDesc('id')->cursor() as $txn) {
                fputcsv($handle, [
                    'TXN-'.str_pad((string) $txn->id, 6, '0', STR_PAD_LEFT),
                    $txn->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
                    WalletTransactionsTable::typeLabel($txn->transaction_type),
                    $txn->subOrder?->sub_order_number ?? 'N/A',
                    WalletTransactionsTable::formatSignedAmount($txn),
                    number_format((float) $txn->running_balance, 2),
                    WalletTransactionsTable::statusLabel($txn->status),
                    $txn->reference_note ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, $headers);
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SellerStatementOverviewWidget::class,
        ];
    }
}
