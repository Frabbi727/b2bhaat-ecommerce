<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Accounting\SellerLedgerStatementService;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletStatementController extends Controller
{
    /**
     * Display printable official financial statement & escrow settlement report.
     */
    public function showStatement(Request $request): View
    {
        $user = $request->user();

        $seller = $this->resolveAuthorizedSeller($user, $request);

        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );

        $transactions = $wallet->transactions()
            ->with('subOrder')
            ->latest('id')
            ->get();

        // ACC-19: totals come from the seller's ledger lines
        $summary = app(SellerLedgerStatementService::class)->summary($seller->id);

        return view('seller.wallet-statement', [
            'seller' => $seller,
            'wallet' => $wallet,
            'transactions' => $transactions,
            'summary' => $summary,
            'totalReleased' => $summary['net_earnings'],
            'totalPayout' => $summary['total_withdrawn'],
            'totalHeld' => $summary['escrow_held'],
            'generatedAt' => now(),
        ]);
    }

    /**
     * Export merchant ledger statement as a CSV file.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();
        $seller = $this->resolveAuthorizedSeller($user, $request);

        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            ['available_balance' => 0.00, 'escrow_held_balance' => 0.00]
        );

        $transactions = $wallet->transactions()
            ->with('subOrder')
            ->latest('id')
            ->get();

        $storeSlug = $seller->sellerProfile?->store_slug ?? 'merchant';
        $filename = "b2bhaat-ledger-{$storeSlug}-".now()->format('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        \app(AuditService::class)->recordExport('wallet ledger statement', $filename, auth()->id());

        $summary = app(SellerLedgerStatementService::class)->summary($seller->id);

        return response()->streamDownload(function () use ($transactions, $seller, $summary) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Metadata Headers
            fputcsv($handle, ['B2B HAAT ESCROW LOGISTICS & SETTLEMENT LEDGER']);
            fputcsv($handle, ['Merchant:', $seller->sellerProfile?->store_name ?? $seller->name]);
            fputcsv($handle, ['Trade License:', $seller->trade_license_number ?? 'N/A']);
            fputcsv($handle, ['Available Balance (BDT):', number_format($summary['available_balance'], 2, '.', '')]);
            fputcsv($handle, ['Escrow Held (BDT):', number_format($summary['escrow_held'], 2, '.', '')]);
            fputcsv($handle, ['Payouts Waiting (BDT):', number_format($summary['pending_payouts'], 2, '.', '')]);
            fputcsv($handle, ['Net Earnings Credited (BDT):', number_format($summary['net_earnings'], 2, '.', '')]);
            fputcsv($handle, ['Fees Paid From Balance (BDT):', number_format($summary['fees_paid_from_balance'], 2, '.', '')]);
            fputcsv($handle, ['Total Withdrawn (BDT):', number_format($summary['total_withdrawn'], 2, '.', '')]);
            fputcsv($handle, ['Generated At:', now()->toDateTimeString()]);
            fputcsv($handle, []); // empty line

            // Column Headers
            fputcsv($handle, [
                'Transaction ID',
                'Date & Time',
                'Type',
                'Sub-Order #',
                'Amount (BDT)',
                'Running Balance (BDT)',
                'Reference Note',
            ]);

            foreach ($transactions as $txn) {
                $typeLabel = match ($txn->transaction_type) {
                    'escrow_hold' => 'Escrow Hold',
                    'escrow_release' => 'Settled to Balance',
                    'escrow_refund' => 'Escrow Refund (Cancelled)',
                    'payout' => 'Payout Withdrawal',
                    default => ucfirst(str_replace('_', ' ', $txn->transaction_type)),
                };

                fputcsv($handle, [
                    'TXN-'.str_pad((string) $txn->id, 6, '0', STR_PAD_LEFT),
                    $txn->created_at->format('Y-m-d H:i:s'),
                    $typeLabel,
                    $txn->subOrder?->sub_order_number ?? 'N/A',
                    number_format($txn->amount, 2, '.', ''),
                    number_format($txn->running_balance, 2, '.', ''),
                    $txn->reference_note ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, $headers);
    }

    /**
     * Resolve and verify authorized seller for statement generation.
     */
    protected function resolveAuthorizedSeller(User $user, Request $request): User
    {
        $isAdmin = $user->role?->slug === 'super_admin';
        $isSeller = $user->role?->slug === 'seller';

        if (! $isAdmin && ! $isSeller) {
            abort(403, 'Unauthorized. Access reserved for registered merchants and platform administrators.');
        }

        if ($isAdmin && $request->has('seller_id')) {
            return User::findOrFail($request->query('seller_id'));
        }

        return $user;
    }
}
