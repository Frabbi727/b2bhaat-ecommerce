<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Events\Sellers\SellerKycDecidedEvent;
use App\Events\Sellers\SellerRegisteredEvent;
use App\Events\Sellers\WholesaleKycDecidedEvent;
use App\Mail\Customer\WholesaleDecisionMail;
use App\Mail\Seller\SellerKycDecisionMail;
use App\Mail\Seller\SellerRegistrationReceivedMail;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SellerKycEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_receives_confirmation_upon_registration(): void
    {
        Mail::fake();

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $user = User::factory()->create([
            'role_id' => $sellerRole->id,
            'email' => 'newfactory@b2bhaat.com',
            'name' => 'Factory Owner',
        ]);

        $profile = SellerProfile::create([
            'user_id' => $user->id,
            'store_name' => 'Dhaka Fabrics Ltd',
            'store_slug' => 'dhaka-fabrics-1234',
            'trade_license_number' => 'TRAD/123456/2026',
            'commission_rate_percentage' => 10.00,
            'is_approved' => false,
        ]);

        event(new SellerRegisteredEvent($profile));

        Mail::assertQueued(SellerRegistrationReceivedMail::class, function (SellerRegistrationReceivedMail $mail) {
            return $mail->hasTo('newfactory@b2bhaat.com');
        });
    }

    public function test_seller_receives_approval_email(): void
    {
        Mail::fake();

        $sellerRole = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $user = User::factory()->create([
            'role_id' => $sellerRole->id,
            'email' => 'approvedfactory@b2bhaat.com',
            'name' => 'Approved Merchant',
        ]);

        $profile = SellerProfile::create([
            'user_id' => $user->id,
            'store_name' => 'Nails & Screws Industry',
            'store_slug' => 'nails-screws-4321',
            'is_approved' => true,
            'commission_rate_percentage' => 8.50,
        ]);

        event(new SellerKycDecidedEvent($profile, true));

        Mail::assertQueued(SellerKycDecisionMail::class, function (SellerKycDecisionMail $mail) {
            return $mail->hasTo('approvedfactory@b2bhaat.com');
        });
    }

    public function test_wholesale_buyer_receives_decision_email(): void
    {
        Mail::fake();

        $buyerRole = Role::firstOrCreate(['slug' => 'wholesale_buyer'], ['name' => 'Wholesale Buyer']);
        $user = User::factory()->create([
            'role_id' => $buyerRole->id,
            'email' => 'bulkbuyer@retailer.com',
            'name' => 'Boutique Store',
            'is_wholesale_approved' => true,
        ]);

        event(new WholesaleKycDecidedEvent($user, true));

        Mail::assertQueued(WholesaleDecisionMail::class, function (WholesaleDecisionMail $mail) {
            return $mail->hasTo('bulkbuyer@retailer.com');
        });
    }
}
