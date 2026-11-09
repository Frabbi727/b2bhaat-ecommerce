<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Filament\Admin\Resources\FlashSales\Pages\EditFlashSale;
use App\Filament\Admin\Resources\FlashSales\RelationManagers\SubmissionsRelationManager;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FS-2: approving a seller's offer must not put a product into two campaigns that run at the same time.
 */
class FlashSaleApprovalConflictTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->actingAs(User::factory()->create([
            'role_id' => Role::where('slug', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->product = Product::firstOrFail();
        $this->seller = User::findOrFail($this->product->seller_id);

        // A live campaign already runs this product for the next week.
        $running = FlashSale::create([
            'name' => 'Running Sale', 'slug' => 'running-sale',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(7),
            'status' => FlashSaleStatus::Scheduled,
        ]);
        $this->offer($running, FlashSaleSubmissionStatus::Approved);
    }

    private function offer(FlashSale $sale, FlashSaleSubmissionStatus $status): FlashSaleProduct
    {
        return FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'status' => $status,
            'discount_type' => FlashSaleDiscountType::Percentage,
            'discount_value' => 10.00,
            'units_sold' => 0,
        ]);
    }

    private function campaign(string $slug, FlashSaleStatus $status): FlashSale
    {
        return FlashSale::create([
            'name' => 'Weekend '.$slug, 'slug' => $slug,
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(4),
            'status' => $status, 'requires_approval' => true,
        ]);
    }

    private function relationManager(FlashSale $sale): mixed
    {
        return Livewire::test(SubmissionsRelationManager::class, [
            'ownerRecord' => $sale,
            'pageClass' => EditFlashSale::class,
        ]);
    }

    public function test_approving_an_overlapping_offer_is_refused(): void
    {
        $sale = $this->campaign('weekend-a', FlashSaleStatus::Scheduled);
        $submission = $this->offer($sale, FlashSaleSubmissionStatus::Pending);

        $this->relationManager($sale)
            ->callTableAction('approve', $submission)
            ->assertNotified('Cannot approve this offer');

        $this->assertSame(FlashSaleSubmissionStatus::Pending, $submission->fresh()->status);
    }

    public function test_bulk_approval_skips_overlapping_offers_and_says_so(): void
    {
        $sale = $this->campaign('weekend-b', FlashSaleStatus::Enrolling);
        $clash = $this->offer($sale, FlashSaleSubmissionStatus::Pending);
        $other = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->product->category_id,
            'brand_id' => $this->product->brand_id, 'sales_channel_id' => $this->product->sales_channel_id,
            'unit_id' => $this->product->unit_id, 'title' => 'Second Product', 'slug' => 'second-product-fs2',
            'buy_price' => 100.00, 'is_active' => true,
        ]);
        $fine = FlashSaleProduct::factory()->create([
            'flash_sale_id' => $sale->id, 'product_id' => $other->id, 'seller_id' => $other->seller_id,
            'status' => FlashSaleSubmissionStatus::Pending, 'units_sold' => 0,
        ]);

        $this->relationManager($sale)->callTableBulkAction('bulkApprove', [$clash, $fine]);

        $this->assertSame(FlashSaleSubmissionStatus::Pending, $clash->fresh()->status);
        $this->assertSame(FlashSaleSubmissionStatus::Approved, $fine->fresh()->status);
    }

    public function test_an_offer_without_overlap_is_approved(): void
    {
        $sale = FlashSale::create([
            'name' => 'Later', 'slug' => 'later',
            'starts_at' => now()->addDays(8), 'ends_at' => now()->addDays(9),
            'status' => FlashSaleStatus::Scheduled,
        ]);
        $submission = $this->offer($sale, FlashSaleSubmissionStatus::Pending);

        $this->relationManager($sale)->callTableAction('approve', $submission)->assertHasNoTableActionErrors();

        $this->assertSame(FlashSaleSubmissionStatus::Approved, $submission->fresh()->status);
    }
}
