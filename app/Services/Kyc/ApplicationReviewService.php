<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place seller-store and wholesale-buyer applications are decided, so the KYC inbox, the Sellers list and
 * the Users list can never disagree about what "approve" or "reject" does.
 */
class ApplicationReviewService
{
    public function approveSeller(SellerProfile $profile, float $commissionRatePercentage): void
    {
        $profile->update([
            'is_approved' => true,
            'approved_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
            'commission_rate_percentage' => $commissionRatePercentage,
        ]);
    }

    public function rejectSeller(SellerProfile $profile, string $reason): void
    {
        $profile->update([
            'is_approved' => false,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Approval also makes the account a wholesale buyer, which is what unlocks wholesale prices.
     */
    public function approveWholesaleBuyer(User $user): void
    {
        $user->update([
            'is_wholesale_approved' => true,
            'wholesale_approved_at' => now(),
            'wholesale_rejected_at' => null,
            'wholesale_rejection_reason' => null,
            'role_id' => Role::where('slug', 'wholesale_buyer')->value('id') ?? $user->role_id,
        ]);
    }

    public function rejectWholesaleBuyer(User $user, string $reason): void
    {
        $user->update([
            'is_wholesale_approved' => false,
            'wholesale_rejected_at' => now(),
            'wholesale_rejection_reason' => $reason,
        ]);
    }

    /**
     * Accounts that applied for wholesale access (they gave a business name or trade licence).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function wholesaleApplicants(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('trade_license_number')->orWhereNotNull('business_name'));
    }

    public static function pendingSellerCount(): int
    {
        return SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();
    }

    public static function pendingWholesaleCount(): int
    {
        return self::wholesaleApplicants(User::query())
            ->where('is_wholesale_approved', false)
            ->whereNull('wholesale_rejected_at')
            ->count();
    }
}
