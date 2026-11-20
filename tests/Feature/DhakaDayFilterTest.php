<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\Role;
use App\Models\User;
use App\Services\Formatting\DisplayDate;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Roadmap 5.2: times are stored in UTC but people pick Dhaka days. An order placed at 2 AM Dhaka time belongs to
 * that Dhaka day, and is written in Dhaka time.
 */
class DhakaDayFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_early_morning_order_is_on_its_dhaka_day(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $order = MixedOrderFixture::create('cod', 'unpaid');
        // 19 Nov 20:00 UTC is 20 Nov 02:00 in Dhaka
        $order->forceFill(['created_at' => CarbonImmutable::parse('2026-11-19 20:00:00', 'UTC')])->saveQuietly();

        $this->actingAs(User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id, 'is_active' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['from' => '2026-11-20', 'until' => '2026-11-20'])
            ->assertCanSeeTableRecords([$order])
            ->filterTable('placed', ['from' => '2026-11-19', 'until' => '2026-11-19'])
            ->assertCanNotSeeTableRecords([$order]);

        $this->assertSame('20 Nov 2026, 2:00 AM', DisplayDate::at($order->fresh()->created_at));
    }

    public function test_day_bounds_cover_the_whole_dhaka_day(): void
    {
        $this->assertSame('2026-11-19 18:00:00', DisplayDate::startOfDay('2026-11-20')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 17:59:59', DisplayDate::endOfDay('2026-11-20')->format('Y-m-d H:i:s'));
    }
}
