<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SellerWallet;
use App\Models\User;

/**
 * Wallet balances change only through the escrow ledger. Sellers may read their own wallet; only a
 * super admin may create, change or remove one (SEC-1).
 */
class SellerWalletPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isSeller();
    }

    public function view(User $user, SellerWallet $wallet): bool
    {
        return $user->isSuperAdmin() || $wallet->seller_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SellerWallet $wallet): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SellerWallet $wallet): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
