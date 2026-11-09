<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Jobs\SendSmsJob;
use App\Models\Order;
use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Models\SmsTemplate;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\Support\PhoneNormalizer;

class OrderNotificationService
{
    /**
     * Parcel steps a buyer is told about, as plain words. Internal steps (processing, in transit) send nothing.
     *
     * @var array<string, string>
     */
    public const PARCEL_STATUS_WORDS = [
        'shipped' => 'handed to the courier',
        'out_for_delivery' => 'out for delivery',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
    ];

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
            $message = "Order #{$order->order_number}: {$newStatus}. Track it here: {$trackingUrl}";
        }

        SendSmsJob::dispatch($normalized, $message, 'order_status_update', bypassDeduplication: true);

        return true;
    }

    /**
     * Tell the buyer that one parcel of their order reached a step they care about.
     */
    public function sendParcelStatusUpdate(SubOrder $subOrder, string $statusSlug): bool
    {
        $statusWords = self::PARCEL_STATUS_WORDS[$statusSlug] ?? null;
        $order = $subOrder->order;
        if ($statusWords === null || $order === null) {
            return false;
        }

        $storeName = $subOrder->seller?->sellerProfile?->store_name ?? 'the seller';
        $courierNote = $statusSlug === 'shipped' && filled($subOrder->courier_name)
            ? " ({$subOrder->courier_name}".(filled($subOrder->tracking_number) ? ", tracking {$subOrder->tracking_number}" : '').')'
            : '';

        return $this->sendOrderStatusUpdate($order, "your parcel from {$storeName} is {$statusWords}{$courierNote}", $subOrder->courier_name, $subOrder->tracking_number);
    }

    /**
     * Tell each seller in the order that they have a parcel to prepare.
     * Called once the order can be fulfilled: at checkout for cash on delivery, after the payment check for mobile wallets.
     */
    public function notifySellersOfNewOrder(Order $order): void
    {
        $sellerOrdersUrl = SubOrderResource::getUrl('index', panel: 'seller');

        foreach ($order->subOrders()->with('seller', 'items')->get() as $subOrder) {
            $phone = $subOrder->seller?->phone;
            if (! $phone || ! PhoneNormalizer::isValid($phone)) {
                continue;
            }

            $message = $this->render('seller_new_order', [
                'order_number' => $subOrder->sub_order_number,
                'item_count' => (string) $subOrder->items->sum('quantity'),
                'order_total' => number_format((float) $subOrder->subtotal, 2),
                'seller_orders_url' => $sellerOrdersUrl,
                'store_name' => $this->storeName(),
            ], 'New order #{order_number} on {store_name}: {item_count} item(s), BDT {order_total}. Please prepare it: {seller_orders_url}');

            SendSmsJob::dispatch(PhoneNormalizer::normalize($phone), $message, 'seller_new_order', bypassDeduplication: true);
        }
    }

    /**
     * Tell a wholesale applicant whether their business account was approved.
     */
    public function sendWholesaleDecision(User $user): bool
    {
        if (! $user->phone || ! PhoneNormalizer::isValid($user->phone)) {
            return false;
        }

        $message = $user->is_wholesale_approved
            ? $this->render('wholesale_approved', ['customer_name' => $user->name, 'store_name' => $this->storeName(), 'shop_url' => route('catalog.index')],
                'Dear {customer_name}, your wholesale account on {store_name} is approved. Sign in to see bulk prices: {shop_url}')
            : $this->render('wholesale_rejected', ['customer_name' => $user->name, 'store_name' => $this->storeName(), 'reason' => (string) $user->wholesale_rejection_reason, 'apply_url' => route('wholesale.upgrade')],
                'Dear {customer_name}, your wholesale application on {store_name} was not approved: {reason}. You can apply again: {apply_url}');

        SendSmsJob::dispatch(PhoneNormalizer::normalize($user->phone), $message, 'wholesale_decision', bypassDeduplication: true);

        return true;
    }

    public function sendSubscriptionRenewalReminder(User $seller, SellerSubscription $subscription, SellerInvoice $invoice): bool
    {
        if (empty($seller->phone) || ! PhoneNormalizer::isValid($seller->phone)) {
            return false;
        }

        $message = "Dear {$seller->name}, your B2Bhaat subscription renewal is due on {$invoice->due_date?->format('d M, Y')}. Amount: Tk ".number_format((float) $invoice->total_amount, 2).'. Pay via your seller dashboard.';
        SendSmsJob::dispatch(PhoneNormalizer::normalize($seller->phone), $message, 'subscription_renewal', bypassDeduplication: true);

        return true;
    }

    public function sendGracePeriodWarning(User $seller, SellerSubscription $subscription, int $graceDays): bool
    {
        if (empty($seller->phone) || ! PhoneNormalizer::isValid($seller->phone)) {
            return false;
        }

        $message = "Important: Your B2Bhaat subscription is overdue. You have {$graceDays} days grace period before your active products are hidden.";
        SendSmsJob::dispatch(PhoneNormalizer::normalize($seller->phone), $message, 'grace_period_warning', bypassDeduplication: true);

        return true;
    }

    public function sendAccountSuspendedNotice(User $seller, string $reason): bool
    {
        if (empty($seller->phone) || ! PhoneNormalizer::isValid($seller->phone)) {
            return false;
        }

        $message = "Your B2Bhaat store selling privileges have been suspended: {$reason}. Settle your invoice in the seller dashboard to restore your store.";
        SendSmsJob::dispatch(PhoneNormalizer::normalize($seller->phone), $message, 'account_suspended', bypassDeduplication: true);

        return true;
    }

    public function sendAccountRestoredNotice(User $seller, int $restoredCount): bool
    {
        if (empty($seller->phone) || ! PhoneNormalizer::isValid($seller->phone)) {
            return false;
        }

        $message = "Payment received! Your B2Bhaat store and {$restoredCount} products have been restored. Thank you for partnering with B2Bhaat.";
        SendSmsJob::dispatch(PhoneNormalizer::normalize($seller->phone), $message, 'account_restored', bypassDeduplication: true);

        return true;
    }

    /**
     * PAY-2: tell the buyer their bKash/Nagad order was cancelled because the payment was not confirmed in time.
     */
    public function sendPaymentNotConfirmed(Order $order): bool
    {
        $phone = $this->extractCustomerPhone($order);
        if (! $phone || ! PhoneNormalizer::isValid($phone)) {
            return false;
        }

        $trxId = $order->shipping_address['mfs_transaction_id'] ?? null;
        $trxText = $trxId ? " (TrxID {$trxId})" : '';
        $message = "B2Bhaat: we could not confirm the payment for Order #{$order->order_number}{$trxText}, so the order was cancelled. If you paid, contact support with your TrxID and we will refund you.";
        SendSmsJob::dispatch(PhoneNormalizer::normalize($phone), $message, 'payment_not_confirmed', bypassDeduplication: true);

        return true;
    }

    /**
     * Tell a seller their plan's terms were changed and that their own terms stay until renewal (MON-2).
     */
    public function sendPlanTermsChangeNotice(User $seller, SellerSubscription $subscription, string $planName): bool
    {
        if (empty($seller->phone) || ! PhoneNormalizer::isValid($seller->phone)) {
            return false;
        }

        $renewsOn = $subscription->ends_at?->format('d M, Y') ?? 'your next renewal';
        $message = "B2Bhaat: the {$planName} plan's price, limits or commission have changed. Your current terms stay the same until your renewal on {$renewsOn}; the new terms apply from then. See Billing in your seller dashboard.";
        SendSmsJob::dispatch(PhoneNormalizer::normalize($seller->phone), $message, 'plan_terms_changed', bypassDeduplication: true);

        return true;
    }

    /**
     * Use the admin's active template for this key when there is one, otherwise the fallback text.
     *
     * @param  array<string, string>  $values  placeholder => value
     */
    private function render(string $templateKey, array $values, string $fallback): string
    {
        $template = SmsTemplate::where('key', $templateKey)->where('is_active', true)->value('content_en');
        $text = filled($template) ? $template : $fallback;

        foreach ($values as $placeholder => $value) {
            $text = str_replace('{'.$placeholder.'}', $value, $text);
        }

        return $text;
    }

    private function storeName(): string
    {
        return app(StoreConfigurationService::class)->getStoreIdentity()['name'];
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
