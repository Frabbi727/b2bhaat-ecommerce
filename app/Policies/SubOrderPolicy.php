<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SubOrder;
use App\Models\User;

/**
 * Sub-orders are created only by the order engine. Sellers may read their own; only a super admin
 * may change or remove one (PRA-005).
 */
class SubOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isSeller();
    }

    public function view(User $user, SubOrder $subOrder): bool
    {
        return $user->isSuperAdmin() || $subOrder->seller_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SubOrder $subOrder): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SubOrder $subOrder): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
