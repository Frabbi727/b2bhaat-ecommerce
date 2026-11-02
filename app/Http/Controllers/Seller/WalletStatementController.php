<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerWallet;
use App\Models\User;
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

        $totalReleased = (float) $wallet->transactions()
            ->where('transaction_type', 'escrow_release')
            ->sum('amount');

        $totalPayout = (float) $wallet->transactions()
            ->where('transaction_type', 'payout')
            ->sum('amount');

        $totalHeld = (float) $wallet->escrow_held_balance;

        return view('seller.wallet-statement', [
            'seller' => $seller,
            'wallet' => $wallet,
            'transactions' => $transactions,
            'totalReleased' => $totalReleased,
            'totalPayout' => $totalPayout,
            'totalHeld' => $totalHeld,
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

        return response()->streamDownload(function () use ($transactions, $seller, $wallet) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Metadata Headers
            fputcsv($handle, ['B2B HAAT ESCROW LOGISTICS & SETTLEMENT LEDGER']);
            fputcsv($handle, ['Merchant:', $seller->sellerProfile?->store_name ?? $seller->name]);
            fputcsv($handle, ['Trade License:', $seller->trade_license_number ?? 'N/A']);
            fputcsv($handle, ['Available Balance (BDT):', number_format($wallet->available_balance, 2)]);
            fputcsv($handle, ['Escrow Held (BDT):', number_format($wallet->escrow_held_balance, 2)]);
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
