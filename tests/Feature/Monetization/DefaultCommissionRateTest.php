<?php

declare(strict_types=1);

namespace Tests\Feature\Monetization;

use App\Filament\Admin\Pages\StoreSettings;
use App\Livewire\Storefront\BecomeSeller;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MON-13: the commission a new seller starts on was written into the code (10%) in five places. It is now one
 * store setting the admin can change.
 */
class DefaultCommissionRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    private function adminSetsRate(): void
    {
        app(StoreConfigurationService::class)->set('default_commission_rate', 12.5, 'monetization', 'float');
    }

    public function test_the_default_is_ten_percent_until_the_admin_changes_it(): void
    {
        $this->assertSame(10.0, app(StoreConfigurationService::class)->getDefaultCommissionRate());
    }

    public function test_a_seller_who_signs_up_on_the_website_starts_on_the_store_rate(): void
    {
        $this->adminSetsRate();
        Livewire::test(BecomeSeller::class)
            ->set('storeName', 'Rate Test Traders')
            ->set('ownerName', 'Rate Tester')
            ->set('email', 'rate@traders.test')
            ->set('phone', '+8801722339911')
            ->set('password', 'SecurePass123!')
            ->set('city', 'Dhaka')
            ->call('registerStore')
            ->assertSet('isSubmitted', true);

        $this->assertSame('12.50', User::where('email', 'rate@traders.test')->firstOrFail()->sellerProfile->commission_rate_percentage);
    }

    public function test_a_seller_who_signs_up_through_the_app_starts_on_the_store_rate(): void
    {
        $this->adminSetsRate();
        $this->postJson('/api/v1/auth/register/seller', [
            'name' => 'App Owner',
            'email' => 'app@traders.test',
            'phone' => '+8801799881122',
            'password' => 'SecurePass123!',
            'store_name' => 'App Traders',
        ])->assertCreated();

        $this->assertSame('12.50', User::where('email', 'app@traders.test')->firstOrFail()->sellerProfile->commission_rate_percentage);
    }

    public function test_a_seller_with_no_plan_and_no_own_rate_is_charged_the_store_rate(): void
    {
        $this->adminSetsRate();
        $seller = User::factory()->create(['role_id' => Role::where('slug', 'seller')->value('id')]);

        $rate = app(MonetizationEngineService::class)->resolveCommissionRate(
            $seller,
            Product::firstOrFail(),
            SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail(),
        );

        $this->assertSame(12.5, $rate);
    }

    public function test_the_admin_sets_the_rate_in_store_settings(): void
    {
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(StoreSettings::class)
            ->assertSet('data.default_commission_rate', 10.0)
            ->set('data.default_commission_rate', 9)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(9.0, app(StoreConfigurationService::class)->getDefaultCommissionRate());

        Livewire::test(StoreSettings::class)
            ->set('data.default_commission_rate', 150)
            ->call('save')
            ->assertHasErrors(['data.default_commission_rate']);
    }
}
