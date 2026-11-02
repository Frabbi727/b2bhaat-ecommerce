<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoreSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicStoreConfigurationTest extends TestCase
{
    use RefreshDatabase;

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
}
