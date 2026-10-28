<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\RfqRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotationController extends Controller
{
    /**
     * Show official printable Pro-Forma Quotation sheet.
     */
    public function show(Request $request, int $id): View
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Please log in to view commercial quotations.');
        }

        $rfq = RfqRequest::with([
            'product.unit',
            'product.category',
            'seller.sellerProfile',
            'buyer',
        ])->findOrFail($id);

        // Authorization check: buyer, seller, or super admin
        $isAuthorized = $user->isSuperAdmin()
            || $rfq->seller_id === $user->id
            || $rfq->buyer_id === $user->id
            || ($rfq->buyer_email && strtolower($rfq->buyer_email) === strtolower($user->email));

        if (! $isAuthorized) {
            abort(403, 'Unauthorized access to commercial quotation sheet.');
        }

        $quotedUnitPrice = $rfq->seller_quoted_price ?? $rfq->target_unit_price ?? 0.00;
        $totalQuotedAmount = $quotedUnitPrice * $rfq->target_quantity;

        return view('storefront.quotation-sheet', [
            'rfq' => $rfq,
            'quotedUnitPrice' => $quotedUnitPrice,
            'totalQuotedAmount' => $totalQuotedAmount,
            'sellerProfile' => $rfq->seller?->sellerProfile,
        ]);
    }
}
