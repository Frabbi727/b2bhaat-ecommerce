<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductDuplicationService;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalSchemaTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Category $category;

    private SalesChannel $retail;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
        ]);
        $this->category = Category::create(['name' => 'Fabrics', 'slug' => 'fabrics', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::create($overrides + [
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retail->id,
            'unit_id' => $this->unit->id,
            'title' => 'Cotton Fabric',
            'slug' => 'cotton-fabric-'.uniqid(),
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => true,
        ]);
    }

    private function changeRequest(Product $product, string $status): ProductChangeRequest
    {
        $request = new ProductChangeRequest([
            'product_id' => $product->id,
            'seller_id' => $this->seller->id,
            'proposed_prices' => [],
            'base_prices' => [],
            'base_sales_channel_id' => $this->retail->id,
            'base_category_id' => $this->category->id,
            'base_fingerprint' => str_repeat('a', 64),
            'submitted_by_user_id' => $this->seller->id,
        ]);
        $request->status = $status;
        $request->save();

        return $request;
    }

    public function test_existing_products_are_backfilled_as_approved(): void
    {
        $product = $this->product();
        DB::table('products')->update(['approval_status' => 'draft', 'approved_at' => null]);

        $file = collect(glob(database_path('migrations/*_add_approval_to_products_table.php')))->first();
        (require $file)::backfill();

        $fresh = Product::findOrFail($product->id);
        $this->assertSame(ProductApprovalStatus::Approved, $fresh->approval_status);
        $this->assertTrue($fresh->created_at->equalTo($fresh->approved_at));
        $this->assertNull($fresh->approved_by_user_id);
    }

    public function test_only_one_pending_request_per_product(): void
    {
        $product = $this->product();
        $this->changeRequest($product, ProductChangeRequest::STATUS_PENDING);

        $this->changeRequest($product, ProductChangeRequest::STATUS_REJECTED);
        $this->assertSame(2, ProductChangeRequest::where('product_id', $product->id)->count());

        $this->expectException(QueryException::class);
        $this->changeRequest($product, ProductChangeRequest::STATUS_PENDING);
    }

    public function test_new_products_default_to_draft_and_approval_fields_are_not_fillable(): void
    {
        $product = $this->product(['approval_status' => 'approved']);

        $this->assertSame(ProductApprovalStatus::Draft, $product->approval_status);
        $this->assertSame(ProductApprovalStatus::Draft, $product->fresh()->approval_status);
        $this->assertSame('Waiting for review', ProductApprovalStatus::PendingReview->label());
        $this->assertSame($product->id, $this->changeRequest($product, 'pending')->product->id);
        $this->assertSame($product->id, $product->pendingChange->product_id);
    }

    public function test_demo_catalog_products_are_approved(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->assertGreaterThan(0, Product::count());
        $this->assertSame(0, Product::where('approval_status', '!=', 'approved')->count());
    }

    public function test_duplicate_of_an_approved_product_is_a_draft(): void
    {
        $product = $this->product();
        $product->forceFill([
            'approval_status' => ProductApprovalStatus::Approved,
            'approved_at' => now(),
            'approved_by_user_id' => $this->seller->id,
            'reviewed_by_user_id' => $this->seller->id,
        ])->save();

        $copy = app(ProductDuplicationService::class)->duplicate($product);
        $copy = $copy->fresh();

        $this->assertSame(ProductApprovalStatus::Draft, $copy->approval_status);
        $this->assertNull($copy->approved_at);
        $this->assertNull($copy->approved_by_user_id);
        $this->assertNull($copy->reviewed_by_user_id);
    }

    public function test_catalog_reviewers_are_admin_and_super_admin_only(): void
    {
        foreach (['super_admin' => true, 'admin' => true, 'seller' => false] as $slug => $expected) {
            $user = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => $slug], ['name' => $slug])->id]);
            $this->assertSame($expected, $user->isCatalogReviewer());
        }
    }
}
