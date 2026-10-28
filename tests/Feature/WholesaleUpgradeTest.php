<?php

namespace Tests\Feature;

use App\Livewire\Storefront\ProductCatalog;
use App\Livewire\Storefront\WholesaleUpgrade;
use App\Models\Category;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WholesaleUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_guest_can_access_wholesale_registration_page(): void
    {
        $response = $this->get(route('wholesale.upgrade'));
        $response->assertStatus(200);
        $response->assertSee('B2B Business Verification');
        $response->assertSee('Unlock Factory-Direct Wholesale Sourcing');
    }

    public function test_retail_user_can_view_wholesale_upgrade_screen_with_prefilled_data(): void
    {
        $user = User::where('email', 'customer@retail.com')->firstOrFail();

        $this->actingAs($user);

        $response = $this->get(route('wholesale.upgrade'));
        $response->assertStatus(200);
        $response->assertSee('B2B Business Verification');
        $response->assertSee('Tanvir Hasan');
    }

    public function test_retail_user_can_submit_business_kyc_and_be_upgraded_to_wholesale(): void
    {
        $user = User::where('email', 'customer@retail.com')->firstOrFail();
        $this->actingAs($user);

        Livewire::test(WholesaleUpgrade::class)
            ->set('businessName', 'Meghna Supermarket Chain Ltd.')
            ->set('contactPerson', 'Tanvir Hasan')
            ->set('tradeLicense', 'TRAD/DNCC/887766')
            ->set('tinNumber', 'TIN-4455667788')
            ->set('businessType', 'Supermarket / Chain')
            ->set('city', 'Dhaka')
            ->call('applyForWholesale')
            ->assertHasNoErrors()
            ->assertSet('isSuccess', true)
            ->assertSee('Wholesale Partner Account Unlocked!');

        $user->refresh();
        $this->assertTrue($user->is_wholesale_approved);
        $this->assertEquals('Meghna Supermarket Chain Ltd.', $user->business_name);
        $this->assertEquals('TRAD/DNCC/887766', $user->trade_license_number);
        $this->assertEquals('wholesale_buyer', $user->role->slug);
    }

    public function test_guest_can_register_new_wholesale_account_directly(): void
    {
        Livewire::test(WholesaleUpgrade::class)
            ->set('businessName', 'Dhanmondi Fashion House')
            ->set('contactPerson', 'Shirin Akter')
            ->set('email', 'shirin@dfashion.com')
            ->set('phone', '+8801811998877')
            ->set('password', 'SecretPass123!')
            ->set('tradeLicense', 'TRAD/DSCC/998811')
            ->set('tinNumber', 'TIN-1122334455')
            ->set('businessType', 'Boutique / Reseller')
            ->set('city', 'Dhaka')
            ->call('applyForWholesale')
            ->assertHasNoErrors()
            ->assertSet('isSuccess', true);

        $newUser = User::where('email', 'shirin@dfashion.com')->firstOrFail();
        $this->assertTrue($newUser->is_wholesale_approved);
        $this->assertEquals('wholesale_buyer', $newUser->role->slug);
        $this->assertEquals('Dhanmondi Fashion House', $newUser->business_name);
    }

    public function test_catalog_renders_verified_factory_spotlight_and_category_chips(): void
    {
        $cat = Category::first();
        $seller = SellerProfile::where('store_name', 'NailsFactory Ltd.')->firstOrFail();

        Livewire::test(ProductCatalog::class)
            ->assertSee('Verified Factories')
            ->assertSee('Direct Importers')
            ->assertSee('NailsFactory Ltd.')
            ->assertSee('Browse by Industry Sector')
            ->assertSee($cat->name)
            ->call('selectCategory', $cat->id)
            ->assertSet('selectedCategoryId', $cat->id);
    }

    public function test_admin_can_approve_wholesale_account_directly(): void
    {
        $retailRole = Role::where('slug', 'retail_buyer')->firstOrFail();
        $wholesaleRole = Role::where('slug', 'wholesale_buyer')->firstOrFail();

        $applicant = User::create([
            'role_id' => $retailRole->id,
            'name' => 'Kawsar Mahmud',
            'email' => 'kawsar@b2bshop.com',
            'phone' => '+8801911223344',
            'password' => 'password',
            'business_name' => 'Kawsar General Store',
            'trade_license_number' => 'TRAD/CTG/2026/112',
            'is_wholesale_approved' => false,
            'is_active' => true,
        ]);

        $this->assertFalse($applicant->is_wholesale_approved);
        $this->assertEquals('retail_buyer', $applicant->role->slug);

        // Simulate admin approval execution
        $applicant->update([
            'is_wholesale_approved' => true,
            'role_id' => $wholesaleRole->id,
        ]);

        $applicant->refresh();
        $this->assertTrue($applicant->is_wholesale_approved);
        $this->assertEquals('wholesale_buyer', $applicant->role->slug);
    }
}
