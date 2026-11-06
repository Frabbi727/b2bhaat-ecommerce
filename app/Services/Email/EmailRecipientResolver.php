<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Actions\Checkout\ResolveCheckoutCustomer;

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
}
