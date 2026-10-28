<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariation;
use App\Services\Inventory\StockReservationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected StockReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->service = app(StockReservationService::class);
    }

    public function test_can_reserve_stock_when_sufficient(): void
    {
        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();
        $initialStock = $variation->stock_quantity;

        $this->service->reserveStock([
            ['variation_id' => $variation->id, 'quantity' => 10],
        ]);

        $variation->refresh();
        $this->assertEquals($initialStock - 10, $variation->stock_quantity);
    }

    public function test_throws_exception_when_stock_insufficient(): void
    {
        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();
        $requestedExcessQty = $variation->stock_quantity + 100;

        $this->expectException(InsufficientStockException::class);

        $this->service->reserveStock([
            ['variation_id' => $variation->id, 'quantity' => $requestedExcessQty],
        ]);
    }

    public function test_can_release_stock_back_to_inventory(): void
    {
        $variation = ProductVariation::where('sku', 'NAIL-PNK-MED')->firstOrFail();
        $initialStock = $variation->stock_quantity;

        // Reserve 20
        $this->service->reserveStock([
            ['variation_id' => $variation->id, 'quantity' => 20],
        ]);
        $this->assertEquals($initialStock - 20, $variation->fresh()->stock_quantity);

        // Release 20 back
        $this->service->releaseStock([
            ['variation_id' => $variation->id, 'quantity' => 20],
        ]);
        $this->assertEquals($initialStock, $variation->fresh()->stock_quantity);
    }
}
