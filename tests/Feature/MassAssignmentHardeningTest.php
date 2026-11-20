<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Pages\StoreSettings;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEC-10: fields only the platform may set (moderation block, counters, suspension memory, approval and billing
 * state) can't be set by passing them in a form or array.
 */
class MassAssignmentHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_product_moderation_and_counter_fields_are_not_mass_assignable(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $product->update([
            'title' => 'Renamed',
            'blocked_at' => null,
            'blocked_reason' => 'forged',
            'sales_count' => 9999,
            'views_count' => 9999,
            'was_active_before_suspension' => true,
        ]);

        $product->refresh();
        $this->assertSame('Renamed', $product->title);
        $this->assertNull($product->blocked_reason);
        $this->assertNotSame(9999, $product->sales_count);
        $this->assertNotSame(9999, $product->views_count);
        $this->assertFalse((bool) $product->was_active_before_suspension);
    }

    public function test_a_seller_cannot_forge_approval_or_billing_fields_in_store_settings(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $seller->sellerProfile->update(['monetization_status' => 'grace_period', 'commission_rate_percentage' => 10.00]);
        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(StoreSettings::class)
            ->set('data.monetization_status', 'active')
            ->set('data.commission_rate_percentage', 0)
            ->set('data.is_approved', true)
            ->call('save');

        $profile = $seller->sellerProfile->fresh();
        $this->assertSame('grace_period', $profile->monetization_status);
        $this->assertSame(10.0, (float) $profile->commission_rate_percentage);
    }
}
