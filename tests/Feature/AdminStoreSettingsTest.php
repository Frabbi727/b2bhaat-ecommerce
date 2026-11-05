<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\StoreSettings;
use App\Models\User;
use App\Services\Settings\StoreConfigurationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminStoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $config = app(StoreConfigurationService::class);
        $config->set('helpline_phone', '+880 9612-000000', 'general', 'string');
        $config->set('support_email', 'support@b2bhaat.test', 'general', 'string');
        $config->set('store_address', 'Motijheel, Dhaka-1000', 'general', 'string');
        $config->set('vat_bin_number', '000000000-0101', 'general', 'string');
    }

    public function test_admin_changes_delivery_and_contact_details_and_the_shop_uses_them(): void
    {
        Livewire::test(StoreSettings::class)
            ->assertSee('Charged once for each seller in the order')
            ->fillForm([
                'helpline_phone' => '+880 1700-000111',
                'delivery_fee_sub_dhaka' => 120,
                'delivery_sla_sub_dhaka' => '2-3 days',
                'cod_max_order_limit' => 30000,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $config = app(StoreConfigurationService::class);
        $this->assertSame(120.0, $config->getDeliveryFee('sub_dhaka'));
        $this->assertSame('2-3 days', $config->getDeliverySla('sub_dhaka'));
        $this->assertSame(30000.0, $config->getCodMaxLimit());

        $this->get('/')->assertOk()->assertSee('+880 1700-000111');
    }

    public function test_clearing_the_announcement_removes_it(): void
    {
        Livewire::test(StoreSettings::class)
            ->fillForm(['announcement_text' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('', app(StoreConfigurationService::class)->getStoreIdentity()['announcement']);
    }

    public function test_blank_or_negative_values_are_not_saved(): void
    {
        $before = app(StoreConfigurationService::class)->getDeliveryFee('inside_dhaka');

        Livewire::test(StoreSettings::class)
            ->fillForm([
                'delivery_fee_inside_dhaka' => -50,
                'support_email' => 'not-an-email',
                'store_name' => '',
            ])
            ->call('save')
            ->assertHasFormErrors(['delivery_fee_inside_dhaka' => 'min', 'support_email' => 'email', 'store_name' => 'required']);

        $this->assertSame($before, app(StoreConfigurationService::class)->getDeliveryFee('inside_dhaka'));
    }
}
