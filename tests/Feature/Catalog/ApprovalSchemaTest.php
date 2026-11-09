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

        $updatedAt = DB::table('products')->where('id', $product->id)->value('updated_at');

        (require database_path('migrations/2026_11_08_153557_backfill_product_approval_status.php'))::backfill();

        $fresh = Product::findOrFail($product->id);
        $this->assertSame(ProductApprovalStatus::Approved, $fresh->approval_status);
        $this->assertTrue($fresh->created_at->equalTo($fresh->approved_at));
        $this->assertNull($fresh->approved_by_user_id);
        $this->assertSame($updatedAt, DB::table('products')->where('id', $product->id)->value('updated_at'));
    }

    public function test_the_backfill_migration_skips_a_database_where_approval_is_already_live(): void
    {
        $pending = $this->product();
        $draft = $this->product();
        DB::table('products')->update(['approval_status' => 'draft', 'approved_at' => null]);
        DB::table('products')->where('id', $pending->id)->update(['approval_status' => 'pending_review']);

        (require database_path('migrations/2026_11_08_153557_backfill_product_approval_status.php'))->up();

        $this->assertSame('pending_review', DB::table('products')->where('id', $pending->id)->value('approval_status'));
        $this->assertSame('draft', DB::table('products')->where('id', $draft->id)->value('approval_status'));
        $this->assertNull(DB::table('products')->where('id', $draft->id)->value('approved_at'));
    }

    public function test_the_backfill_migration_resumes_after_a_partial_run(): void
    {
        $done = $this->product();
        $left = $this->product();
        DB::table('products')->update(['approval_status' => 'draft', 'approved_at' => null]);
        DB::table('products')->where('id', $done->id)->update(['approval_status' => 'approved', 'approved_at' => now()->subDay()]);

        (require database_path('migrations/2026_11_08_153557_backfill_product_approval_status.php'))->up();

        $this->assertSame('approved', DB::table('products')->where('id', $left->id)->value('approval_status'));
        $this->assertTrue(now()->subDay()->isSameDay(Product::findOrFail($done->id)->approved_at));
    }

    public function test_the_backfill_migration_approves_a_catalog_that_is_all_draft(): void
    {
        $first = $this->product();
        $second = $this->product();
        DB::table('products')->update(['approval_status' => 'draft', 'approved_at' => null]);

        (require database_path('migrations/2026_11_08_153557_backfill_product_approval_status.php'))->up();

        foreach ([$first, $second] as $product) {
            $fresh = Product::findOrFail($product->id);
            $this->assertSame(ProductApprovalStatus::Approved, $fresh->approval_status);
            $this->assertTrue($fresh->created_at->equalTo($fresh->approved_at));
        }
    }

    public function test_the_backfill_runs_on_its_own_outside_a_transaction_right_after_the_columns(): void
    {
        $migrations = collect(glob(database_path('migrations/2026_11_08_*.php')))->map(fn (string $path): string => basename($path, '.php'))->values();
        $columns = $migrations->search('2026_11_08_153554_add_approval_to_products_table');
        $backfill = $migrations->search('2026_11_08_153557_backfill_product_approval_status');

        $this->assertNotFalse($columns);
        $this->assertNotFalse($backfill);
        $this->assertGreaterThan($columns, $backfill);
        $this->assertSame([], $migrations->slice($columns + 1, $backfill - $columns - 1)
            ->filter(fn (string $name): bool => str_contains($name, 'products'))->values()->all());

        $migration = require database_path('migrations/2026_11_08_153557_backfill_product_approval_status.php');
        $this->assertFalse($migration->withinTransaction);

        $this->assertStringNotContainsString('backfill', (string) file_get_contents(database_path('migrations/2026_11_08_153554_add_approval_to_products_table.php')));

        $product = $this->product();
        DB::table('products')->update(['approval_status' => 'draft', 'approved_at' => null]);
        $migration->down();
        $this->assertSame('draft', DB::table('products')->where('id', $product->id)->value('approval_status'));
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
