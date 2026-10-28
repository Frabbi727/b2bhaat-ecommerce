<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Inventory\StockBulkUpdateService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductStockBulkController extends Controller
{
    public function __construct(
        protected StockBulkUpdateService $stockBulkUpdateService
    ) {}

    /**
     * Download CSV stock inventory sheet.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();

        // Support admin auditing via query param or default to logged-in user
        if ($user->isSuperAdmin() && $request->filled('seller_id')) {
            $seller = User::whereHas('sellerProfile')->findOrFail((int) $request->query('seller_id'));
        } else {
            $seller = $user;
        }

        if (! $seller->sellerProfile) {
            abort(403, 'Unauthorized. Only approved sellers can export inventory sheets.');
        }

        return $this->stockBulkUpdateService->exportCsvForSeller($seller);
    }

    /**
     * Handle bulk stock update from CSV upload.
     */
    public function importCsv(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->sellerProfile) {
            abort(403, 'Unauthorized.');
        }

        $request->validate([
            'csv_file' => 'required_without:csv_text|nullable|file|mimes:csv,txt',
            'csv_text' => 'required_without:csv_file|nullable|string',
        ]);

        try {
            $input = $request->file('csv_file') ?? $request->input('csv_text');
            $result = $this->stockBulkUpdateService->importCsvForSeller($user, $input);

            $msg = "Inventory updated! {$result['updated']} variation(s) updated, {$result['unchanged']} unchanged.";
            if (! empty($result['errors'])) {
                $msg .= ' ('.count($result['errors']).' skipped with warnings).';
            }

            return redirect()->back()->with('success', $msg);
        } catch (Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
