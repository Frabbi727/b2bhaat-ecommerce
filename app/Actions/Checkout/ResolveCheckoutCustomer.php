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
 * a unique, undeliverable address. A returning guest's phone links to the earlier guest or retail
 * account, but never to a seller, admin or wholesale account.
 */
class ResolveCheckoutCustomer
{
    public const GUEST_EMAIL_DOMAIN = 'guest.b2bhaat.invalid';

    private const LINKABLE_ROLE_SLUGS = ['retail_buyer'];

    public function handle(?User $user, string $name, string $phone): User
    {
        if ($user) {
            return $user;
        }

        $normalizedPhone = PhoneNormalizer::normalize($phone);
        $existing = User::wherePhone($normalizedPhone)->with('role')->first();

        if ($existing) {
            if (! in_array($existing->role?->slug, self::LINKABLE_ROLE_SLUGS, true) || $existing->isWholesaleBuyer()) {
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
}
