<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Jobs\SendSmsJob;
use App\Models\Order;
use App\Models\SmsTemplate;
use App\Services\Sms\Support\PhoneNormalizer;

class OrderNotificationService
{
    /**
     * Send order confirmation SMS upon checkout completion.
     */
    public function sendOrderConfirmation(Order $order): bool
    {
        if ($order->sms_confirmation_sent_at !== null) {
            return false; // Prevent duplicate SMS
        }

        $phone = $this->extractCustomerPhone($order);
        if (! $phone || ! PhoneNormalizer::isValid($phone)) {
            return false;
        }

        $normalized = PhoneNormalizer::normalize($phone);

        $invoiceUrl = route('order.invoice.token', ['token' => $order->invoice_token]);
        $trackingUrl = route('order.track.token', ['token' => $order->tracking_token]);

        $customerName = $order->shipping_address['recipient_name'] ?? $order->customer?->name ?? 'Valued Customer';
        $orderTotal = number_format((float) $order->total_amount, 2);

        $template = SmsTemplate::where('key', 'order_confirmation')->where('is_active', true)->first();

        if ($template && ! empty($template->content_en)) {
            $message = str_replace(
                ['{customer_name}', '{order_number}', '{order_total}', '{invoice_url}', '{tracking_url}'],
                [$customerName, $order->order_number, $orderTotal, $invoiceUrl, $trackingUrl],
                $template->content_en
            );
        } else {
            $message = "Dear {$customerName}, your order #{$order->order_number} has been placed successfully. Total: BDT {$orderTotal}. Invoice: {$invoiceUrl}. Tracking: {$trackingUrl}. Thank you for shopping with B2Bhaat.";
        }

        SendSmsJob::dispatch($normalized, $message, 'order_confirmation', bypassDeduplication: true);

        $order->update([
            'sms_confirmation_sent_at' => now(),
        ]);

        return true;
    }

    /**
     * Send or resend order invoice voucher link.
     */
    public function sendInvoiceLink(Order $order): bool
    {
        $phone = $this->extractCustomerPhone($order);
        if (! $phone || ! PhoneNormalizer::isValid($phone)) {
            return false;
        }

        $normalized = PhoneNormalizer::normalize($phone);
        $invoiceUrl = route('order.invoice.token', ['token' => $order->invoice_token]);
        $customerName = $order->shipping_address['recipient_name'] ?? $order->customer?->name ?? 'Valued Customer';

        $template = SmsTemplate::where('key', 'order_invoice_link')->where('is_active', true)->first();

        if ($template && ! empty($template->content_en)) {
            $message = str_replace(
                ['{customer_name}', '{order_number}', '{invoice_url}'],
                [$customerName, $order->order_number, $invoiceUrl],
                $template->content_en
            );
        } else {
            $message = "Your B2Bhaat order #{$order->order_number} invoice is ready: {$invoiceUrl}";
        }

        SendSmsJob::dispatch($normalized, $message, 'order_invoice', bypassDeduplication: true);

        return true;
    }

    /**
     * Send SMS on status changes (e.g. Shipped, Delivered).
     */
    public function sendOrderStatusUpdate(
        Order $order,
        string $newStatus,
        ?string $courierName = null,
        ?string $trackingNumber = null
    ): bool {
        $phone = $this->extractCustomerPhone($order);
        if (! $phone || ! PhoneNormalizer::isValid($phone)) {
            return false;
        }

        $normalized = PhoneNormalizer::normalize($phone);
        $trackingUrl = route('order.track.token', ['token' => $order->tracking_token]);
        $customerName = $order->shipping_address['recipient_name'] ?? $order->customer?->name ?? 'Valued Customer';

        $template = SmsTemplate::where('key', 'order_tracking_update')->where('is_active', true)->first();

        if ($template && ! empty($template->content_en)) {
            $message = str_replace(
                ['{customer_name}', '{order_number}', '{order_status}', '{tracking_url}', '{courier_name}', '{tracking_number}'],
                [$customerName, $order->order_number, $newStatus, $trackingUrl, $courierName ?? '', $trackingNumber ?? ''],
                $template->content_en
            );
        } else {
            $message = "Your order #{$order->order_number} status is now: {$newStatus}. Track parcel: {$trackingUrl}";
        }

        SendSmsJob::dispatch($normalized, $message, 'order_status_update', bypassDeduplication: true);

        return true;
    }

    protected function extractCustomerPhone(Order $order): ?string
    {
        if (! empty($order->shipping_address['recipient_phone'])) {
            return (string) $order->shipping_address['recipient_phone'];
        }

        if (! empty($order->customer?->phone)) {
            return (string) $order->customer->phone;
        }

        return null;
    }
}
