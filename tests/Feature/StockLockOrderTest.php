<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductVariation;
use App\Models\User;
use App\Services\Inventory\StockBulkUpdateService;
use App\Services\Inventory\StockReservationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PAY-6: two checkouts that lock the same stock rows in opposite order can deadlock. Every path that changes
 * stock for several variations at once locks them lowest id first, once each.
 */
class StockLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariation $first;

    private ProductVariation $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        [$this->first, $this->second] = ProductVariation::whereHas('product', fn ($query) => $query->where('seller_id', $seller->id))
            ->orderBy('id')->take(2)->get()->all();
        $this->first->update(['stock_quantity' => 50]);
        $this->second->update(['stock_quantity' => 50]);
    }

    /**
     * Ids of the variation rows read one at a time, in the order they were read.
     *
     * @return list<int>
     */
    private function variationReadOrder(callable $work): array
    {
        $ids = [];
        DB::listen(function ($query) use (&$ids): void {
            if (preg_match('/^select .* from "product_variations" where "(product_variations"\.")?id" = \?/', $query->sql)) {
                $ids[] = (int) $query->bindings[0];
            }
        });

        $work();

        return $ids;
    }

    public function test_reserving_locks_rows_lowest_id_first_and_merges_repeats(): void
    {
        $order = $this->variationReadOrder(fn () => app(StockReservationService::class)->reserveStock([
            ['variation_id' => $this->second->id, 'quantity' => 1],
            ['variation_id' => $this->first->id, 'quantity' => 1],
            ['variation_id' => $this->second->id, 'quantity' => 2],
        ]));

        $this->assertSame([$this->first->id, $this->second->id], $order);
        $this->assertSame(49, $this->first->fresh()->stock_quantity);
        $this->assertSame(47, $this->second->fresh()->stock_quantity);
    }

    public function test_repeated_rows_are_checked_against_stock_together(): void
    {
        $this->second->update(['stock_quantity' => 2]);

        $this->expectExceptionMessage('Requested: 3');
        app(StockReservationService::class)->reserveStock([
            ['variation_id' => $this->second->id, 'quantity' => 2],
            ['variation_id' => $this->second->id, 'quantity' => 1],
        ]);
    }

    public function test_releasing_locks_rows_lowest_id_first(): void
    {
        $order = $this->variationReadOrder(fn () => app(StockReservationService::class)->releaseStock([
            ['variation_id' => $this->second->id, 'quantity' => 4],
            ['variation_id' => $this->first->id, 'quantity' => 1],
            ['variation_id' => $this->second->id, 'quantity' => 1],
        ]));

        $this->assertSame([$this->first->id, $this->second->id], $order);
        $this->assertSame(51, $this->first->fresh()->stock_quantity);
        $this->assertSame(55, $this->second->fresh()->stock_quantity);
    }

    public function test_bulk_stock_update_locks_rows_lowest_id_first(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $csv = "variation_id,sku,new_stock_quantity\n{$this->second->id},,7\n{$this->first->id},,9\n";

        $result = null;
        $order = $this->variationReadOrder(function () use ($seller, $csv, &$result): void {
            $result = app(StockBulkUpdateService::class)->importCsvForSeller($seller, $csv);
        });

        $this->assertSame([$this->first->id, $this->second->id], $order);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(9, $this->first->fresh()->stock_quantity);
        $this->assertSame(7, $this->second->fresh()->stock_quantity);
    }
}
