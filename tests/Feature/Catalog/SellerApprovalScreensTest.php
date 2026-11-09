<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Filament\Seller\Resources\Products\RelationManagers\ChangeRequestsRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Catalog\ProductChangeDecided;
use App\Notifications\Catalog\ProductReviewDecided;
use App\Services\Catalog\PriceSet;
use App\Services\Catalog\ProductApprovalService;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SellerApprovalScreensTest extends TestCase
{
    use RefreshDatabase;

    private const REJECTION_BN = 'ছবি পরিষ্কার নয়, আবার জমা দিন';

    private User $seller;

    private Category $category;

    private Unit $unit;

    private SalesChannel $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        NotificationFacade::fake();
        Cache::flush();

        $this->seller = $this->makeSeller('screens-store');
        User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin'])->id,
            'is_active' => true,
        ]);

        $this->category = Category::firstOrCreate(['slug' => 'fabrics'], ['name' => 'Fabrics', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function makeSeller(string $slug): User
    {
        $seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => $slug,
            'store_slug' => $slug,
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);

        return $seller;
    }

    private function product(string $title, ProductApprovalStatus $status = ProductApprovalStatus::Approved, bool $active = true, float $price = 110): Product
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
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => $price]);
        $product->ensureDefaultVariation()->update(['stock_quantity' => 5]);

        return $product->fresh();
    }

    private function propose(Product $product, float $price, ?int $categoryId = null): ProductChangeRequest
    {
        $prices = PriceSet::fromInput([
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => $price, 'min_quantity' => 1, 'max_quantity' => null],
        ], 500);

        return app(ProductApprovalService::class)->proposeChange(
            $product,
            $this->seller,
            $prices,
            $product->sales_channel_id,
            $categoryId ?? $product->category_id,
            null,
        );
    }

    private function edit(Product $product): Testable
    {
        return Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    }

    public function test_the_list_shows_review_badges_and_the_price_change_badge(): void
    {
        $waiting = $this->product('Waiting Shirt', ProductApprovalStatus::PendingReview);
        $live = $this->product('Live Shirt');
        $this->propose($live, 120);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$waiting, $live])
            ->assertSee('Waiting for review')
            ->assertSee('Price change waiting');
    }

    public function test_the_list_can_be_filtered_by_review_status(): void
    {
        $waiting = $this->product('Waiting Shirt', ProductApprovalStatus::PendingReview);
        $live = $this->product('Live Shirt');

        Livewire::test(ListProducts::class)
            ->filterTable('approval_status', ProductApprovalStatus::PendingReview->value)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$live]);
    }

    public function test_the_list_does_not_query_pending_changes_per_row(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $product = $this->product("Item {$name}");
            $this->propose($product, 130);
        }

        \DB::enableQueryLog();
        Livewire::test(ListProducts::class)->assertSee('Price change waiting');
        $pendingQueries = collect(\DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'product_change_requests'));
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $pendingQueries->count());
    }

    public function test_the_edit_page_shows_the_waiting_change_with_old_and_new_prices(): void
    {
        $product = $this->product('Live Shirt');
        $this->propose($product, 120);

        $this->edit($product)
            ->assertSee('Waiting for approval: new prices')
            ->assertSee('৳110.00')
            ->assertSee('৳120.00')
            ->assertSee('Buyers still pay the current prices shown below.');
    }

    public function test_the_callout_names_a_category_change(): void
    {
        $product = $this->product('Live Shirt');
        $other = Category::firstOrCreate(['slug' => 'buttons'], ['name' => 'Buttons', 'is_active' => true]);
        $this->propose($product, 110, $other->id);

        $this->edit($product)
            ->assertSee('Waiting for approval: new category')
            ->assertSee('Category: Fabrics')
            ->assertSee('Buttons');
    }

    public function test_withdraw_marks_the_request_withdrawn_and_clears_the_callout(): void
    {
        $product = $this->product('Live Shirt');
        $request = $this->propose($product, 120);

        $this->edit($product)
            ->callAction(TestAction::make('withdrawChange')->schemaComponent('pendingChangeCallout', schema: 'form'))
            ->assertNotified('Price change withdrawn')
            ->assertDontSee('Waiting for approval');

        $this->assertSame(ProductChangeRequest::STATUS_WITHDRAWN, $request->fresh()->status);
    }

    public function test_withdraw_is_hidden_when_the_request_belongs_to_someone_else(): void
    {
        $product = $this->product('Live Shirt');
        $request = $this->propose($product, 120);
        $request->forceFill(['seller_id' => $this->makeSeller('other-store')->id])->save();

        $this->edit($product)
            ->assertActionHidden(TestAction::make('withdrawChange')->schemaComponent('pendingChangeCallout', schema: 'form'));
    }

    public function test_a_rejected_price_change_shows_its_reason_and_can_be_dismissed(): void
    {
        $product = $this->product('Live Shirt');
        $request = $this->propose($product, 120);
        app(ProductApprovalService::class)->rejectChange($request, User::query()->whereHas('role', fn ($q) => $q->where('slug', 'admin'))->firstOrFail(), 'Price is too far from the market');

        $this->edit($product)
            ->assertSee('Price is too far from the market')
            ->callAction(TestAction::make('dismissRejectedChange')->schemaComponent('rejectedChangeCallout', schema: 'form'))
            ->assertDontSee('Price is too far from the market');
    }

    public function test_the_history_tab_replaces_the_word_superseded(): void
    {
        $product = $this->product('Live Shirt');
        $first = $this->propose($product, 120);
        $this->propose($product, 125);

        $this->assertSame(ProductChangeRequest::STATUS_SUPERSEDED, $first->fresh()->status);

        Livewire::test(ChangeRequestsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertCanSeeTableRecords($product->changeRequests)
            ->assertSee('Replaced by a newer price')
            ->assertDontSee('superseded');
    }

    public function test_the_history_tab_names_the_team_not_the_reviewer(): void
    {
        $product = $this->product('Live Shirt');
        $request = $this->propose($product, 120);
        $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'admin'))->firstOrFail();
        $admin->forceFill(['name' => 'Reviewer Rahima Khatun'])->save();
        app(ProductApprovalService::class)->rejectChange($request, $admin, 'Price is too far from the market');

        Livewire::test(ChangeRequestsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertTableColumnStateSet('reviewer', 'B2Bhaat team', $request->fresh())
            ->assertSee('B2Bhaat team')
            ->assertDontSee('Reviewer Rahima Khatun');
    }

    public function test_view_on_storefront_is_disabled_for_a_draft(): void
    {
        $product = $this->product('Draft Shirt', ProductApprovalStatus::Draft, active: false);

        $this->edit($product)->assertActionDisabled('viewOnStorefront');
    }

    public function test_a_rejected_products_reason_shows_in_the_subheading_with_bengali_intact(): void
    {
        $product = $this->product('Rejected Shirt', ProductApprovalStatus::Rejected, active: false);
        $product->forceFill(['rejection_reason' => self::REJECTION_BN, 'rejected_at' => now()])->save();

        $page = $this->edit($product);

        $this->assertSame('Not approved: '.self::REJECTION_BN, $page->instance()->getSubheading());
        $page->assertSee(self::REJECTION_BN);
    }

    public function test_a_waiting_products_subheading_shows_the_submitted_date(): void
    {
        $product = $this->product('Waiting Shirt', ProductApprovalStatus::PendingReview);
        $product->forceFill(['submitted_at' => '2026-11-03 10:00:00'])->save();

        $this->assertSame('Waiting for review since 3 Nov', $this->edit($product)->instance()->getSubheading());
    }

    public function test_the_seller_panel_has_a_notification_bell(): void
    {
        $this->assertTrue(Filament::getPanel('seller')->hasDatabaseNotifications());
    }

    public function test_seller_decision_notifications_render_in_filaments_format(): void
    {
        $product = $this->product('Live Shirt');
        $request = $this->propose($product, 120);

        $review = (new ProductReviewDecided($product, false, 'Blurry photos'))->toDatabase($this->seller);
        $change = (new ProductChangeDecided($request, true))->toDatabase($this->seller);

        foreach ([$review, $change] as $payload) {
            $this->assertArrayHasKey('title', $payload);
            $this->assertArrayHasKey('body', $payload);
            $this->assertArrayHasKey('actions', $payload);
            $this->assertArrayHasKey('status', $payload);
        }
        $this->assertSame($product->id, $review['product_id']);
        $this->assertFalse($review['approved']);
        $this->assertSame('Blurry photos', $review['reason']);
        $this->assertSame($request->id, $change['request_id']);
        $this->assertStringContainsString('Live Shirt', $review['body'].$review['title']);
    }

    public function test_a_rejection_reason_is_escaped_in_the_subheading_and_the_rejected_change_callout(): void
    {
        $raw = '<script>alert(1)</script>';
        $escaped = '&lt;script&gt;alert(1)&lt;/script&gt;';

        $rejected = $this->product('Rejected Shirt', ProductApprovalStatus::Rejected, active: false);
        $rejected->forceFill(['rejection_reason' => $raw, 'rejected_at' => now()])->save();
        $this->edit($rejected)->assertDontSee($raw, false)->assertSee($escaped, false);

        $live = $this->product('Live Shirt');
        $request = $this->propose($live, 120);
        app(ProductApprovalService::class)->rejectChange($request, User::query()->whereHas('role', fn ($q) => $q->where('slug', 'admin'))->firstOrFail(), $raw.' bad');
        $this->edit($live)->assertDontSee($raw, false)->assertSee($escaped, false);
    }

    public function test_the_callout_marks_added_and_removed_tiers(): void
    {
        $added = $this->product('Added Tier');
        app(ProductApprovalService::class)->proposeChange($added, $this->seller, PriceSet::fromInput([
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => 110, 'min_quantity' => 1, 'max_quantity' => 9],
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => 100, 'min_quantity' => 10, 'max_quantity' => null],
        ], 500), $added->sales_channel_id, $added->category_id, null);
        $this->edit($added)->assertSee('৳100.00')->assertSee('new');

        $removed = $this->product('Removed Tier');
        $removed->prices()->update(['max_quantity' => 9]);
        $removed->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 10, 'unit_price' => 100]);
        app(ProductApprovalService::class)->proposeChange($removed->fresh(), $this->seller, PriceSet::fromInput([
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => 110, 'min_quantity' => 1, 'max_quantity' => null],
        ], 500), $removed->sales_channel_id, $removed->category_id, null);
        $this->edit($removed)->assertSee('removed')->assertSee('৳100.00');
    }

    public function test_the_callout_shows_a_channel_change(): void
    {
        $both = SalesChannel::firstOrCreate(['slug' => SalesChannel::BOTH], ['name' => 'Both', 'is_active' => true]);
        $product = $this->product('Channel Shirt');
        $wholesale = SalesChannel::firstOrCreate(['slug' => SalesChannel::WHOLESALE], ['name' => 'Wholesale', 'is_active' => true]);
        app(ProductApprovalService::class)->proposeChange($product, $this->seller, PriceSet::fromInput([
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => 110, 'min_quantity' => 1, 'max_quantity' => null],
            ['sales_channel_id' => $wholesale->id, 'variation_id' => null, 'unit_price' => 90, 'min_quantity' => 10, 'max_quantity' => null],
        ], 500), $both->id, $product->category_id, null);

        $this->edit($product)->assertSee('Channel: Retail Only (B2C) → Hybrid Dual Channel (Both)');
    }

    public function test_the_visible_toggle_tooltip_is_truthful_for_unapproved_products(): void
    {
        $this->product('Waiting Shirt', ProductApprovalStatus::PendingReview);
        $this->product('Draft Shirt', ProductApprovalStatus::Draft, active: false);

        Livewire::test(ListProducts::class)
            ->assertSee('Waiting for review. Buyers will see it once approved.')
            ->assertSee('Turning this on sends it for review')
            ->assertDontSee('Shown on your storefront');
    }
}
