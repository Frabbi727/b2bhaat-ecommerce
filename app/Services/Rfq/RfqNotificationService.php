<?php

declare(strict_types=1);

namespace App\Services\Rfq;

use App\Jobs\SendSmsJob;
use App\Models\RfqRequest;
use App\Services\Sms\Support\PhoneNormalizer;
use Illuminate\Support\Str;

class RfqNotificationService
{
    /**
     * Notify factory seller via SMS that a new wholesale RFQ has been submitted.
     */
    public function notifySellerOfNewInquiry(RfqRequest $rfq): void
    {
        $rfq->loadMissing(['product', 'seller']);

        $phone = $rfq->seller?->phone;
        if (! $this->isValidPhone($phone)) {
            return;
        }

        $normalizedPhone = PhoneNormalizer::normalize($phone);
        $productTitle = Str::limit($rfq->product?->title ?? 'Product', 40);
        $message = "B2Bhaat: New Wholesale RFQ #{$rfq->id} received for '{$productTitle}'. Qty: {$rfq->target_quantity} pcs. Please review and quote at b2bhaat.com/seller";

        SendSmsJob::dispatch($normalizedPhone, $message, 'rfq_new_inquiry');
    }

    /**
     * Notify buyer via SMS when seller has provided an official quotation rate and terms.
     */
    public function notifyBuyerOfQuotation(RfqRequest $rfq): void
    {
        $rfq->loadMissing(['product', 'seller.sellerProfile']);

        $phone = $rfq->buyer_phone ?: $rfq->buyer?->phone;
        if (! $this->isValidPhone($phone)) {
            return;
        }

        $normalizedPhone = PhoneNormalizer::normalize($phone);
        $productTitle = Str::limit($rfq->product?->title ?? 'Product', 40);
        $formattedPrice = number_format((float) $rfq->seller_quoted_price, 2);
        $quoteUrl = route('rfq.quotation.print', $rfq->id);

        $message = "B2Bhaat: Factory has quoted your RFQ #{$rfq->id} for '{$productTitle}' at BDT {$formattedPrice}/pc. View quotation: {$quoteUrl}";

        SendSmsJob::dispatch($normalizedPhone, $message, 'rfq_quote_sent');
    }

    /**
     * Notify factory seller via SMS that prospective buyer has accepted quotation and initiated order.
     */
    public function notifySellerOfAcceptedQuote(RfqRequest $rfq): void
    {
        $rfq->loadMissing(['product', 'seller']);

        $phone = $rfq->seller?->phone;
        if (! $this->isValidPhone($phone)) {
            return;
        }

        $normalizedPhone = PhoneNormalizer::normalize($phone);
        $productTitle = Str::limit($rfq->product?->title ?? 'Product', 40);
        $message = "B2Bhaat: Buyer has accepted Quotation #{$rfq->id} for '{$productTitle}' ({$rfq->target_quantity} pcs) and proceeded to checkout. You will be notified once order is placed.";

        SendSmsJob::dispatch($normalizedPhone, $message, 'rfq_quote_accepted');
    }

    /**
     * Check if a phone string is present and valid for SMS delivery.
     */
    protected function isValidPhone(?string $phone): bool
    {
        if ($phone === null || trim($phone) === '') {
            return false;
        }

        return PhoneNormalizer::isValid($phone);
    }
}
