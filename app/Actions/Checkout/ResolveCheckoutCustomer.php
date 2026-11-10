<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Models\Role;
use App\Models\User;
use App\Services\Sms\Support\PhoneNormalizer;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Decides which user account a checkout is placed under.
 *
 * Guests never get a loginable account: the password is random and unknown, and the email is
 * a unique, undeliverable address. A returning guest's phone links to the earlier guest account only.
 * A phone that belongs to a registered account (one whose owner proved the phone, or any seller,
 * admin or wholesale account) is refused, so a stranger cannot put orders into someone else's account.
 */
class ResolveCheckoutCustomer
{
    public const GUEST_EMAIL_DOMAIN = 'guest.b2bhaat.invalid';

    public function handle(?User $user, string $name, string $phone): User
    {
        if ($user) {
            return $user;
        }

        $normalizedPhone = PhoneNormalizer::normalize($phone);
        $existing = User::wherePhone($normalizedPhone)->with('role')->first();

        if ($existing) {
            if (! self::isUnclaimedGuest($existing)) {
                throw new DomainException('This phone belongs to a registered account. Please sign in to order.');
            }

            return $existing;
        }

        return User::create([
            'role_id' => Role::where('slug', 'retail_buyer')->firstOrFail()->id,
            'name' => $name,
            'email' => 'guest+'.Str::uuid().'@'.self::GUEST_EMAIL_DOMAIN,
            'phone' => $normalizedPhone,
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
        ]);
    }

    /**
     * A hidden account made by guest checkout that nobody has signed up for yet.
     * Whoever proves the phone with an OTP may take it over, keeping its orders.
     */
    public static function isUnclaimedGuest(User $user): bool
    {
        $user->loadMissing('role');

        return $user->role?->slug === 'retail_buyer'
            && ! $user->isWholesaleBuyer()
            && $user->phone_verified_at === null
            && str_ends_with((string) $user->email, '@'.self::GUEST_EMAIL_DOMAIN);
    }
}
