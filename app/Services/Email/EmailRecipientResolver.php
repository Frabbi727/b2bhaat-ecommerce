<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Actions\Checkout\ResolveCheckoutCustomer;
use App\Models\Order;

class EmailRecipientResolver
{
    /**
     * Determine whether an email address is valid, deliverable, and not a synthetic guest dummy domain.
     */
    public function isDeliverable(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        $email = trim(strtolower($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Reject synthetic guest accounts generated during unauthenticated checkout
        if (str_ends_with($email, ResolveCheckoutCustomer::GUEST_EMAIL_DOMAIN) || str_ends_with($email, '.invalid')) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the best deliverable notification email for an order.
     * Checks shipping address recipient_email first, then falls back to customer account email.
     */
    public function resolveOrderCustomerEmail(Order $order): ?string
    {
        $shippingEmail = $order->shipping_address['recipient_email'] ?? null;
        if ($this->isDeliverable($shippingEmail)) {
            return trim(strtolower((string) $shippingEmail));
        }

        $customerEmail = $order->customer?->email;
        if ($this->isDeliverable($customerEmail)) {
            return trim(strtolower((string) $customerEmail));
        }

        return null;
    }
}
