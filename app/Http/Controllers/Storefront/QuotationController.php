<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Services\Cart\CartSessionService;
use App\Services\Rfq\RfqNotificationService;
use Illuminate\Http\RedirectResponse;
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

    /**
     * Accept the seller's custom quoted rate and convert RFQ into an active wholesale cart item.
     */
    public function acceptAndAddToCart(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Please log in to accept commercial quotations.');
        }

        $rfq = RfqRequest::with(['product'])->findOrFail($id);

        $isBuyer = $rfq->buyer_id === $user->id
            || ($rfq->buyer_email && strtolower($rfq->buyer_email) === strtolower((string) $user->email));

        if (! $isBuyer && ! $user->isSuperAdmin()) {
            abort(403, 'Only the prospective buyer can accept this custom quotation.');
        }

        if ($rfq->isExpired()) {
            return redirect()->back()->with('error', 'This quotation has expired and can no longer be accepted.');
        }

        if (empty($rfq->seller_quoted_price)) {
            return redirect()->back()->with('error', 'This RFQ has not yet received an official quotation from the seller.');
        }

        // Only an open quote can be accepted, and only once (final review I6)
        $accepted = RfqRequest::whereKey($rfq->id)->where('status', 'quoted')->update(['status' => 'accepted']);
        if ($accepted === 0) {
            return redirect()->back()->with('error', 'This quotation is no longer open for acceptance.');
        }

        $rfq->refresh();
        // The guarded update above skips model events, so record the acceptance explicitly (AUD-4)
        $rfq->recordAuditActivity('updated', ['status' => 'quoted'], ['status' => 'accepted']);
        app(RfqNotificationService::class)->notifySellerOfAcceptedQuote($rfq);

        // 2. Add item to cart with locked wholesale quotation unit price
        $cart = app(CartSessionService::class);
        $cart->addItem(
            productId: $rfq->product_id,
            variationId: null,
            channelSlug: SalesChannel::WHOLESALE,
            quantity: (int) $rfq->target_quantity,
            overrideUnitPrice: (float) $rfq->seller_quoted_price
        );

        return redirect()->route('checkout.index')->with(
            'success',
            "Quotation #QT-RFQ-{$rfq->id} accepted! Added {$rfq->target_quantity}x units at the agreed factory rate of ৳".number_format((float) $rfq->seller_quoted_price, 2).'.'
        );
    }

    /**
     * Decline an open commercial quotation.
     */
    public function decline(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Please log in to decline commercial quotations.');
        }

        $rfq = RfqRequest::findOrFail($id);

        $isBuyer = $rfq->buyer_id === $user->id
            || ($rfq->buyer_email && strtolower($rfq->buyer_email) === strtolower((string) $user->email));

        if (! $isBuyer && ! $user->isSuperAdmin()) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $rejectedBy = ($user->isSuperAdmin() && ! $isBuyer) ? 'admin' : 'buyer';

        $affected = RfqRequest::whereKey($rfq->id)
            ->whereIn('status', ['pending', 'quoted'])
            ->update([
                'status' => 'rejected',
                'rejected_by' => $rejectedBy,
                'rejection_reason' => $request->input('rejection_reason'),
            ]);

        if ($affected === 0) {
            return redirect()->back()->with('error', 'This quotation can no longer be declined.');
        }

        return redirect()->back()->with(
            'success',
            'Quotation #QT-RFQ-'.$rfq->id.' has been declined.'
        );
    }
}
