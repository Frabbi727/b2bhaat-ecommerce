<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\KycApprovals;
use App\Livewire\Storefront\WholesaleUpgrade;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WholesaleApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_URL = '/product/luxury-press-on-fake-nails-set';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    private function applyAs(User $user): void
    {
        $this->actingAs($user);
        Livewire::test(WholesaleUpgrade::class)
            ->set('businessName', 'Probe Boutique')
            ->set('contactPerson', 'Probe')
            ->set('tradeLicense', 'TRAD/DNCC/123456')
            ->call('applyForWholesale');
    }

    public function test_wholesale_application_is_not_self_approved(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();

        $this->applyAs($customer);

        $customer->refresh();
        $this->assertFalse((bool) $customer->is_wholesale_approved, 'Wholesale self-approved without admin (D3)');
        $this->assertSame('retail_buyer', $customer->role->slug);
        $this->assertTrue($customer->isWholesalePending());
        $this->assertFalse($customer->canSeeWholesalePrices());
    }

    public function test_seller_role_is_not_overwritten_by_wholesale_application(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $this->applyAs($seller);

        $this->assertSame('seller', $seller->fresh()->role->slug);
        $this->assertFalse((bool) $seller->fresh()->is_wholesale_approved);
    }

    public function test_api_wholesale_registration_does_not_grant_wholesale_role(): void
    {
        $this->postJson('/api/v1/auth/register/wholesale', [
            'name' => 'X', 'email' => 'x@x.test', 'phone' => '01799112233', 'password' => 'password123',
            'business_name' => 'X Traders', 'trade_license_number' => 'TL-1',
        ])->assertSuccessful();

        $user = User::where('email', 'x@x.test')->firstOrFail();
        $this->assertNotSame('wholesale_buyer', $user->role->slug, 'API grants wholesale role without approval');
        $this->assertFalse($user->canSeeWholesalePrices());
        $this->assertTrue($user->isWholesalePending());
    }

    public function test_guest_and_retail_do_not_see_tier_prices_on_product_page(): void
    {
        $this->get(self::PRODUCT_URL)->assertOk()
            ->assertDontSee('Factory Volume Tier Brackets')
            ->assertDontSee('৳110.00')
            ->assertSee('Lower prices for bulk orders');

        $this->actingAs(User::where('email', 'customer@retail.com')->firstOrFail())
            ->get(self::PRODUCT_URL)->assertOk()
            ->assertDontSee('৳110.00');
    }

    public function test_seeded_approved_wholesale_buyer_sees_tier_prices(): void
    {
        $this->actingAs(User::where('email', 'buyer@boutique.com')->firstOrFail())
            ->get(self::PRODUCT_URL)->assertOk()
            ->assertSee('Factory Volume Tier Brackets')
            ->assertSee('৳110.00');
    }

    public function test_admin_approval_grants_access(): void
    {
        $customer = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->applyAs($customer);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Livewire::test(KycApprovals::class)
            ->call('openApproveWholesale', $customer->id)
            ->call('confirmApproveWholesale');

        $customer->refresh();
        $this->assertSame('wholesale_buyer', $customer->role->slug);
        $this->assertTrue($customer->canSeeWholesalePrices());
    }
}
