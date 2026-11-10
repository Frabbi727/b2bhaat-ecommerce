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
 * Finds or makes the account for a shopper who confirmed their phone with an SMS code at checkout.
 *
 * Every order comes from a confirmed phone, so nobody can order with, or into the account of, a number
 * they do not hold. A new shopper gets an account without typing an email or password: the password is
 * random and unknown and the email is a unique, undeliverable address. They can register later with the
 * same phone to set a real email and password (see mayTakeOverByRegistration()).
 */
class ResolveCheckoutCustomer
{
    public const GUEST_EMAIL_DOMAIN = 'guest.b2bhaat.invalid';

    public const STAFF_PHONE_MESSAGE = 'This phone belongs to a seller or staff account. Please sign in with your password to order.';

    /**
     * Refuse before any SMS is sent when the phone's account may not shop by SMS code.
     *
     * @throws DomainException
     */
    public function ensureMayConfirmByPhone(string $phone): void
    {
        $existing = User::wherePhone(PhoneNormalizer::normalize($phone))->first();

        if ($existing && ! self::mayShopByPhoneCode($existing)) {
            throw new DomainException(self::STAFF_PHONE_MESSAGE);
        }
    }

    /**
     * Call only after the phone was confirmed with an SMS code.
     *
     * @throws DomainException
     */
    public function forConfirmedPhone(string $name, string $phone): User
    {
        $normalizedPhone = PhoneNormalizer::normalize($phone);
        $existing = User::wherePhone($normalizedPhone)->first();

        if ($existing) {
            if (! self::mayShopByPhoneCode($existing)) {
                throw new DomainException(self::STAFF_PHONE_MESSAGE);
            }

            if ($existing->phone_verified_at === null) {
                $existing->update(['phone_verified_at' => now()]);
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
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * Shoppers may confirm by SMS code; seller and admin accounts sign in with their password (SEC-7).
     */
    public static function mayShopByPhoneCode(User $user): bool
    {
        return $user->is_active && ! $user->isSuperAdmin() && ! $user->isSeller();
    }

    /**
     * Registering (which proves the phone with an OTP) may set a new name, email and password on an
     * existing shopper account with that phone, keeping its orders. That gives no more access than
     * signing in by OTP already does, and lets a checkout-made account get a password.
     * Seller, admin and wholesale (KYC) accounts are never taken over this way.
     */
    public static function mayTakeOverByRegistration(User $user): bool
    {
        $user->loadMissing('role');

        return $user->is_active
            && $user->role?->slug === 'retail_buyer'
            && ! $user->isWholesaleBuyer();
    }
}
