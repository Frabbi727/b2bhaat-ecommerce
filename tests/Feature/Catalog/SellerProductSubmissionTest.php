<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerPlan;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Catalog\ProductSubmittedForReview;
use App\Services\Inventory\ProductBulkImportService;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SellerProductSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $admin;

    private Category $category;

    private Unit $unit;

    private SalesChannel $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        Notification::fake();
        Cache::flush();

        $this->seller = $this->userWithRole('seller');
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Submission Store',
            'store_slug' => 'submission-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $this->admin = $this->userWithRole('admin');

        $this->category = Category::firstOrCreate(['slug' => 'fabrics'], ['name' => 'Fabrics', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)])->id,
            'is_active' => true,
        ]);
    }

    private function product(string $title, bool $active, string $status = 'draft'): Product
    {
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retail->id,
            'unit_id' => $this->unit->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => $active,
        ]);
        $product->forceFill(['approval_status' => $status])->save();
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => 500]);
        $product->ensureDefaultVariation();

        return $product->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function newProductData(): array
    {
        return [
            'title' => 'Submission Kit',
            'slug' => 'submission-kit',
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'sales_channel_id' => $this->retail->id,
            'has_variations' => false,
            'simple_stock_quantity' => 10,
            'is_active' => true,
            'prices' => [[
                'sales_channel_id' => $this->retail->id,
                'unit_price' => 350,
                'min_quantity' => 1,
                'max_quantity' => null,
            ]],
        ];
    }

    private function limitToOneListing(): void
    {
        $plan = SellerPlan::create([
            'name' => 'One Listing',
            'slug' => 'one-listing',
            'subscription_price' => 0,
            'max_active_products' => 1,
            'is_listing_limit_enabled' => true,
            'is_active' => true,
        ]);
        SellerSubscription::create([
            'seller_id' => $this->seller->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
    }

    public function test_publish_sends_for_review_and_draft_does_not(): void
    {
        $undo = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->newProductData())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Sent for review');

        $published = Product::where('slug', 'submission-kit')->sole();
        $this->assertSame(ProductApprovalStatus::PendingReview, $published->approval_status);
        $this->assertTrue($published->is_active);
        Notification::assertSentTo($this->admin, ProductSubmittedForReview::class);

        Notification::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm(array_merge($this->newProductData(), ['title' => 'Hidden Kit', 'slug' => 'hidden-kit']))
            ->callAction('saveDraft')
            ->assertHasNoFormErrors();

        $undo();

        $draft = Product::where('slug', 'hidden-kit')->sole();
        $this->assertSame(ProductApprovalStatus::Draft, $draft->approval_status);
        $this->assertFalse($draft->is_active);
        Notification::assertNothingSent();
    }

    public function test_publish_notification_body_tells_the_seller_what_happens_next(): void
    {
        $undo = Repeater::fake();

        $component = Livewire::test(CreateProduct::class)
            ->fillForm($this->newProductData())
            ->call('create');

        $undo();

        $component->assertNotified(
            \Filament\Notifications\Notification::make()
                ->title('Sent for review')
                ->body('Buyers will see it once an admin approves it.')
                ->success()
        );
    }

    public function test_visible_toggle_on_a_draft_submits_and_off_withdraws(): void
    {
        $draft = $this->product('Toggle Me', false);

        Livewire::test(ListProducts::class)
            ->call('updateTableColumnState', 'is_active', $draft->getKey(), true);

        $draft->refresh();
        $this->assertTrue($draft->is_active);
        $this->assertSame(ProductApprovalStatus::PendingReview, $draft->approval_status);
        Notification::assertSentTo($this->admin, ProductSubmittedForReview::class);

        Livewire::test(ListProducts::class)
            ->call('updateTableColumnState', 'is_active', $draft->getKey(), false);

        $draft->refresh();
        $this->assertFalse($draft->is_active);
        $this->assertSame(ProductApprovalStatus::Draft, $draft->approval_status);
    }

    public function test_visible_toggle_on_an_approved_product_only_activates_and_hides(): void
    {
        $approved = $this->product('Already Approved', false, 'approved');

        Livewire::test(ListProducts::class)
            ->call('updateTableColumnState', 'is_active', $approved->getKey(), true);

        $this->assertTrue($approved->fresh()->is_active);
        $this->assertSame(ProductApprovalStatus::Approved, $approved->fresh()->approval_status);

        Livewire::test(ListProducts::class)
            ->call('updateTableColumnState', 'is_active', $approved->getKey(), false);

        $this->assertFalse($approved->fresh()->is_active);
        $this->assertSame(ProductApprovalStatus::Approved, $approved->fresh()->approval_status);
    }

    public function test_bulk_show_on_a_mix(): void
    {
        $approved = $this->product('Approved Hidden', false, 'approved');
        $draft = $this->product('Plain Draft', false);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('show', [$approved, $draft])
            ->assertNotified('1 shown, 1 sent for review');

        $this->assertTrue($approved->fresh()->is_active);
        $this->assertSame(ProductApprovalStatus::Approved, $approved->fresh()->approval_status);
        $this->assertTrue($draft->fresh()->is_active);
        $this->assertSame(ProductApprovalStatus::PendingReview, $draft->fresh()->approval_status);
    }

    public function test_import_with_visible_yes_sends_for_review(): void
    {
        $columns = array_keys(ProductBulkImportService::columns());
        $row = fn (string $title, string $visible): string => implode(',', array_merge(array_fill_keys($columns, ''), [
            'product_title' => $title,
            'category' => 'Fabrics',
            'sell_to' => 'retail',
            'unit' => 'pcs',
            'stock' => '5',
            'retail_price' => '300',
            'visible' => $visible,
        ]));
        $csv = implode("\n", [implode(',', $columns), $row('Import Shown', 'yes'), $row('Import Hidden', 'no')]);

        $result = app(ProductBulkImportService::class)->import($this->seller, $csv);

        $this->assertSame(['Import Shown'], $result['sent_for_review']);
        $this->assertSame(ProductApprovalStatus::PendingReview, Product::where('title', 'Import Shown')->sole()->approval_status);
        $this->assertSame(ProductApprovalStatus::Draft, Product::where('title', 'Import Hidden')->sole()->approval_status);

        $dry = app(ProductBulkImportService::class)->import($this->seller, str_replace('Import ', 'Dry ', $csv), dryRun: true);
        $this->assertSame([], $dry['sent_for_review']);
    }

    public function test_listing_limit_still_applies_to_publish(): void
    {
        $this->limitToOneListing();
        $this->product('Live One', true);
        $undo = Repeater::fake();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->newProductData())
            ->call('create');

        $undo();

        $this->assertDatabaseMissing('products', ['slug' => 'submission-kit']);
        Notification::assertNothingSent();
    }
}
