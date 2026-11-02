<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoreSetting;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
