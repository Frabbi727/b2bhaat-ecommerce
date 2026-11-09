<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalog\PriceSet;
use App\Services\Catalog\ProductApprovalService;
use App\Services\Inventory\StockBulkUpdateService;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SellerProductEditApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const HELD_COPY = 'Changes saved. Your new prices are waiting for approval; buyers still pay the current prices.';

    private User $seller;

    private Category $category;

    private Category $otherCategory;

    private Unit $unit;

    private SalesChannel $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        NotificationFacade::fake();
        Cache::flush();

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Edit Store',
            'store_slug' => 'edit-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin'])->id,
            'is_active' => true,
        ]);

        $this->category = Category::firstOrCreate(['slug' => 'fabrics'], ['name' => 'Fabrics', 'is_active' => true]);
        $this->otherCategory = Category::firstOrCreate(['slug' => 'buttons'], ['name' => 'Buttons', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function product(string $status = 'approved', bool $active = true, bool $withVariationPrice = false): Product
    {
        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'sales_channel_id' => $this->retail->id,
            'unit_id' => $this->unit->id,
            'title' => 'Edit Me',
            'slug' => 'edit-me',
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => $active,
        ]);
        $product->forceFill(['approval_status' => $status])->save();
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => 400]);
        $variation = $product->ensureDefaultVariation();
        $variation->update(['stock_quantity' => 7]);

        if ($withVariationPrice) {
            $product->prices()->create([
                'sales_channel_id' => $this->retail->id,
                'variation_id' => $variation->id,
                'min_quantity' => 1,
                'unit_price' => 350,
            ]);
        }

        return $product->fresh();
    }

    private function edit(Product $product): Testable
    {
        return Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function retailTier(int|float $price): array
    {
        return ['sales_channel_id' => $this->retail->id, 'unit_price' => $price, 'min_quantity' => 1, 'max_quantity' => null];
    }

    public function test_saving_an_approved_product_untouched_creates_no_request(): void
    {
        $product = $this->product();

        $this->edit($product)->call('save')->assertHasNoFormErrors()->assertNotified('Changes saved');

        $this->assertSame(0, ProductChangeRequest::count());
        $this->assertSame('400.00', $product->prices()->sole()->unit_price);
        $this->assertSame('500.00', $product->fresh()->regular_price);
    }

    public function test_saving_untouched_with_a_variation_price_creates_no_request_and_keeps_the_variation_price(): void
    {
        $product = $this->product(withVariationPrice: true);

        $this->edit($product)->call('save')->assertHasNoFormErrors();

        $this->assertSame(0, ProductChangeRequest::count());
        $this->assertSame(2, $product->prices()->count());
        $this->assertSame(1, $product->prices()->whereNotNull('variation_id')->count());
    }

    public function test_changing_a_base_tier_keeps_the_variation_row_in_the_proposal(): void
    {
        $undo = Repeater::fake();
        $product = $this->product(withVariationPrice: true);
        $variationPriceId = $product->prices()->whereNotNull('variation_id')->value('id');

        $this->edit($product)
            ->fillForm(['prices' => [$this->retailTier(380)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undo();

        $request = ProductChangeRequest::sole();
        $proposed = collect($request->proposed_prices);
        $this->assertCount(2, $proposed);
        $this->assertTrue($proposed->contains(fn (array $row): bool => $row['variation_id'] !== null && $row['unit_price'] === '350.00'));
        $this->assertTrue($proposed->contains(fn (array $row): bool => $row['variation_id'] === null && $row['unit_price'] === '380.00'));
        $this->assertSame(2, $product->prices()->count());
        $this->assertSame('400.00', $product->prices()->whereNull('variation_id')->sole()->unit_price);
        $this->assertSame('350.00', $product->prices()->findOrFail($variationPriceId)->unit_price);
    }

    public function test_editing_title_and_stock_of_an_approved_product_is_instant(): void
    {
        $product = $this->product();

        $stockBefore = $product->variations()->sole()->stock_quantity;

        // INV-3: stock changes through "Adjust stock", which needs no approval either
        $this->edit($product)
            ->fillForm(['title' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('adjustStock', ['change' => 25 - $stockBefore, 'reason' => 'count_correction'])
            ->assertHasNoActionErrors();

        $this->assertSame('Renamed', $product->fresh()->title);
        $this->assertSame(25, $product->variations()->sole()->stock_quantity);
        $this->assertSame(0, ProductChangeRequest::count());
    }

    public function test_changing_a_tier_price_on_an_approved_product_is_held(): void
    {
        $undo = Repeater::fake();
        $product = $this->product();

        $component = $this->edit($product)
            ->fillForm(['prices' => [$this->retailTier(300)], 'regular_price' => 600, 'change_note' => 'Cotton cost fell'])
            ->call('save')
            ->assertHasNoFormErrors();

        $undo();

        $component->assertNotified(Notification::make()->title(self::HELD_COPY)->success());

        $fresh = $product->fresh();
        $this->assertSame('400.00', $fresh->prices()->sole()->unit_price);
        $this->assertSame('500.00', $fresh->regular_price);

        $request = ProductChangeRequest::sole();
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame('Cotton cost fell', $request->seller_note);
        $this->assertSame('600.00', $request->proposed_regular_price);

        $shown = collect($component->get('data.prices'));
        $this->assertCount(1, $shown);
        $this->assertEquals(400, $shown->first()['unit_price']);
        $this->assertEquals(500, $component->get('data.regular_price'));
        $component->assertSet('data.change_note', null);
    }

    public function test_changing_the_category_on_an_approved_product_is_held(): void
    {
        $product = $this->product();

        $this->edit($product)
            ->fillForm(['category_id' => $this->otherCategory->id])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(Notification::make()->title(self::HELD_COPY)->success());

        $this->assertSame($this->category->id, $product->fresh()->category_id);
        $this->assertSame($this->otherCategory->id, ProductChangeRequest::sole()->proposed_category_id);
    }

    public function test_a_refused_proposal_keeps_the_instant_fields_and_explains(): void
    {
        $undo = Repeater::fake();
        $product = $this->product();
        RateLimiter::increment("product-change:{$this->seller->id}", 3600, ProductApprovalService::MAX_CHANGES_PER_HOUR);

        $component = $this->edit($product)
            ->fillForm(['title' => 'Still Saved', 'prices' => [$this->retailTier(300)]])
            ->call('save');

        $undo();

        $component->assertNotified("Your price changes weren't sent");
        $this->assertSame('Still Saved', $product->fresh()->title);
        $this->assertSame(0, ProductChangeRequest::count());
        $this->assertSame('400.00', $product->prices()->sole()->unit_price);
    }

    public function test_editing_a_rejected_product_with_visible_on_resubmits(): void
    {
        $product = $this->product('rejected', active: false);

        $this->edit($product)
            ->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame(ProductApprovalStatus::PendingReview, $product->approval_status);
        $this->assertTrue($product->is_active);
    }

    public function test_a_refused_activation_saves_nothing_including_prices(): void
    {
        $undo = Repeater::fake();
        $product = $this->product('draft', active: false);
        $this->seller->sellerProfile->update(['monetization_status' => 'suspended']);

        $this->edit($product)
            ->fillForm(['is_active' => true, 'title' => 'Renamed', 'prices' => [$this->retailTier(321)]])
            ->call('save')
            ->assertNotified('Product not published');

        $product->refresh();
        $this->assertSame('Edit Me', $product->title);
        $this->assertFalse($product->is_active);
        $this->assertSame(ProductApprovalStatus::Draft, $product->approval_status);
        $this->assertSame('400.00', $product->prices()->sole()->unit_price);

        $undo();
    }

    public function test_unapproved_products_save_prices_directly_with_no_request(): void
    {
        $undo = Repeater::fake();
        $product = $this->product('draft', active: false);
        $priceId = $product->prices()->value('id');

        $this->edit($product)
            ->fillForm(['prices' => ['record-'.$priceId => $this->retailTier(321)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undo();

        $this->assertSame('321.00', $product->prices()->sole()->unit_price);
        $this->assertSame(0, ProductChangeRequest::count());
        $this->assertSame(ProductApprovalStatus::Draft, $product->fresh()->approval_status);
    }

    public function test_turning_visible_off_while_waiting_for_review_withdraws(): void
    {
        $product = $this->product('pending_review', active: true);

        $this->edit($product)
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ProductApprovalStatus::Draft, $product->fresh()->approval_status);
    }

    public function test_stock_sheet_import_is_instant_while_a_change_is_pending(): void
    {
        $product = $this->product();
        app(ProductApprovalService::class)->proposeChange(
            $product,
            $this->seller,
            PriceSet::fromInput([$this->retailTier(300)], 500),
            $this->retail->id,
            $this->category->id,
            null,
        );
        $variation = $product->variations()->sole();

        $result = app(StockBulkUpdateService::class)->importCsvForSeller(
            $this->seller,
            "variation_id,sku,new_stock_quantity\n{$variation->id},{$variation->sku},99\n",
        );

        $this->assertSame(1, $result['updated']);
        $this->assertSame(99, $variation->fresh()->stock_quantity);
        $this->assertSame(1, ProductChangeRequest::where('status', ProductChangeRequest::STATUS_PENDING)->count());
    }

    public function test_unrelated_save_keeps_a_pending_price_change_pending(): void
    {
        $product = $this->product();
        app(ProductApprovalService::class)->proposeChange(
            $product,
            $this->seller,
            PriceSet::fromInput([$this->retailTier(300)], 500),
            $this->retail->id,
            $this->category->id,
            null,
        );

        $this->edit($product)
            ->fillForm(['title' => 'Just a rename'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Just a rename', $product->fresh()->title);
        $this->assertSame(1, ProductChangeRequest::where('status', ProductChangeRequest::STATUS_PENDING)->count());
        $this->assertSame(0, ProductChangeRequest::where('status', ProductChangeRequest::STATUS_WITHDRAWN)->count());
    }

    public function test_changing_the_sales_channel_on_an_approved_product_is_held(): void
    {
        $wholesale = SalesChannel::where('slug', SalesChannel::WHOLESALE)->firstOrFail();
        $undo = Repeater::fake();
        $product = $this->product();

        $this->edit($product)
            ->fillForm([
                'sales_channel_id' => $wholesale->id,
                'prices' => [
                    $this->retailTier(400),
                    ['sales_channel_id' => $wholesale->id, 'unit_price' => 350, 'min_quantity' => 10, 'max_quantity' => null],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $undo();

        $this->assertSame($this->retail->id, $product->fresh()->sales_channel_id);
        $request = ProductChangeRequest::sole();
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame($wholesale->id, $request->proposed_sales_channel_id);
        $this->assertSame(1, $product->prices()->count());
    }

    public function test_a_stale_page_cannot_write_prices_live_after_approval(): void
    {
        $undo = Repeater::fake();
        $product = $this->product('pending_review');
        $component = $this->edit($product);

        $product->forceFill(['approval_status' => ProductApprovalStatus::Approved])->save();

        $component->fillForm(['prices' => [$this->retailTier(250)]])->call('save');

        $undo();

        $this->assertSame('400.00', $product->prices()->sole()->unit_price);
        $this->assertSame(1, ProductChangeRequest::where('status', ProductChangeRequest::STATUS_PENDING)->count());
    }
}
