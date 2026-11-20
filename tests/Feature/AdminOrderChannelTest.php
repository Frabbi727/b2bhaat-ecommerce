<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\MixedOrderFixture;
use Tests\TestCase;

/**
 * Roadmap 5.4 (WA-ADM-04): the admin orders list shows whether each order was retail, wholesale or both, and
 * can be filtered by it.
 */
class AdminOrderChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id, 'is_active' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @param  list<string>  $channels
     */
    private function orderBoughtIn(array $channels): Order
    {
        $order = MixedOrderFixture::create('cod', 'unpaid');
        $parcel = $order->subOrders->first();
        $variation = ProductVariation::query()->orderBy('id')->firstOrFail();

        foreach ($channels as $channel) {
            OrderItem::create([
                'sub_order_id' => $parcel->id,
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'sales_channel_id' => SalesChannel::where('slug', $channel)->value('id'),
                'unit_price' => 100.00,
                'quantity' => 1,
                'total_price' => 100.00,
                'commission_rate' => 10,
            ]);
        }

        return $order;
    }

    public function test_each_order_shows_its_channel_and_the_filter_narrows_the_list(): void
    {
        $retail = $this->orderBoughtIn([SalesChannel::RETAIL]);
        $wholesale = $this->orderBoughtIn([SalesChannel::WHOLESALE]);
        $mixed = $this->orderBoughtIn([SalesChannel::RETAIL, SalesChannel::WHOLESALE]);

        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('channel', 'Retail', $retail)
            ->assertTableColumnStateSet('channel', 'Wholesale', $wholesale)
            ->assertTableColumnStateSet('channel', 'Mixed', $mixed)
            ->filterTable('channel', 'wholesale')
            ->assertCanSeeTableRecords([$wholesale])
            ->assertCanNotSeeTableRecords([$retail, $mixed])
            ->filterTable('channel', 'mixed')
            ->assertCanSeeTableRecords([$mixed])
            ->assertCanNotSeeTableRecords([$retail, $wholesale])
            ->filterTable('channel', 'retail')
            ->assertCanSeeTableRecords([$retail])
            ->assertCanNotSeeTableRecords([$wholesale, $mixed]);
    }
}
