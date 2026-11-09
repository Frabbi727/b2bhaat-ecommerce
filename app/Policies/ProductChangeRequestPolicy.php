<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductChangeRequest;
use App\Models\User;

/**
 * Price, channel and category change requests. Reviewers see and decide everything; sellers see only their own,
 * can withdraw one while it waits, and can never create, edit or delete a request through a panel.
 */
class ProductChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCatalogReviewer() || $user->isSeller();
    }

    public function view(User $user, ProductChangeRequest $request): bool
    {
        return $user->isCatalogReviewer() || $request->seller_id === $user->id;
    }

    public function review(User $user, ProductChangeRequest $request): bool
    {
        return $user->isCatalogReviewer();
    }

    public function withdraw(User $user, ProductChangeRequest $request): bool
    {
        return $request->seller_id === $user->id && $request->status === ProductChangeRequest::STATUS_PENDING;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProductChangeRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, ProductChangeRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ProductChangeRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductChangeRequest $request): bool
    {
        return false;
    }
}
