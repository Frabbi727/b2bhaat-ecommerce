<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isSeller();
    }

    public function view(User $user, AuditLog $log): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isSeller()) {
            return $log->seller_id !== null && (int) $log->seller_id === (int) $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
