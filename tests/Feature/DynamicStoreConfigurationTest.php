<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\StoreSettings;
use App\Models\StoreSetting;
use App\Models\User;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicStoreConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_store_setting_persists_type_and_returns_casted_value(): void
    {
        StoreSetting::setTypedValue('free_shipping_threshold', 10000.00, 'delivery', 'float');
        $this->assertDatabaseHas('store_settings', [
            'key' => 'free_shipping_threshold',
            'type' => 'float',
            'group' => 'delivery',
        ]);

        $val = StoreSetting::getTypedValue('free_shipping_threshold');
        $this->assertSame(10000.00, $val);
    }

    public function test_store_configuration_service_returns_typed_domain_defaults(): void
    {
        $service = app(StoreConfigurationService::class);
        $this->assertEquals(70.00, $service->getDeliveryFee('inside_dhaka'));
        $this->assertEquals(100.00, $service->getDeliveryFee('sub_dhaka'));
        $this->assertEquals(130.00, $service->getDeliveryFee('outside_dhaka'));
        $this->assertEquals(10000.00, $service->getFreeShippingThreshold());
        $this->assertEquals(10, $service->getLowStockThreshold());
        $this->assertEquals(15, $service->getStockReservationTtl());
        $this->assertTrue($service->isGuestCheckoutAllowed());
        $this->assertEquals(24, $service->getOrderCancellationWindowHours());
        $this->assertEquals(50000.00, $service->getCodMaxLimit());
    }

    public function test_super_admin_can_update_settings_and_sellers_are_forbidden(): void
    {
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Admin can access
        $this->actingAs($admin);
        Livewire::test(StoreSettings::class)
            ->assertSuccessful()
            ->set('data.delivery_fee_inside_dhaka', 85.00)
            ->set('data.free_shipping_threshold', 12000.00)
            ->call('save')
            ->assertHasNoErrors();

        $service = app(StoreConfigurationService::class);
        $this->assertEquals(85.00, $service->getDeliveryFee('inside_dhaka'));
        $this->assertEquals(12000.00, $service->getFreeShippingThreshold());

        // Seller is forbidden
        $this->actingAs($seller);
        $this->get('/admin/store-settings')->assertForbidden();
    }
}
