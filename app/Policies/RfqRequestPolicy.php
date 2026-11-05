<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RfqRequest;
use App\Models\User;

/**
 * Buyers create RFQs from the storefront; the addressed seller may quote while it is open (PRA-012).
 */
class RfqRequestPolicy
{
    /** Statuses in which the seller may still quote or decline. */
    public const OPEN_STATUSES = ['pending', 'quoted'];

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isSeller();
    }

    public function view(User $user, RfqRequest $rfq): bool
    {
        return $user->isSuperAdmin() || $rfq->seller_id === $user->id || $rfq->buyer_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RfqRequest $rfq): bool
    {
        return $rfq->seller_id === $user->id && in_array($rfq->status, self::OPEN_STATUSES, true);
    }

    public function delete(User $user, RfqRequest $rfq): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
