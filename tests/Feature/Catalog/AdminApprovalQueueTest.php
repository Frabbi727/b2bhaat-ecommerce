<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Filament\Admin\Resources\ChangeRequestApprovals\ChangeRequestApprovalResource;
use App\Filament\Admin\Resources\ChangeRequestApprovals\Pages\ListChangeRequestApprovals;
use App\Filament\Admin\Resources\ChangeRequestApprovals\Schemas\ChangeRequestReviewDetails;
use App\Filament\Admin\Resources\ProductApprovals\Pages\ListProductApprovals;
use App\Filament\Admin\Resources\ProductApprovals\ProductApprovalResource;
use App\Filament\Admin\Resources\ProductApprovals\Schemas\ProductReviewDetails;
use App\Filament\Admin\Resources\ProductModeration\Pages\ListModeratedProducts;
use App\Filament\Shared\ChangeRequestSummary;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Catalog\ProductChangeSubmitted;
use App\Notifications\Catalog\ProductSubmittedForReview;
use App\Services\Catalog\PriceSet;
use App\Services\Catalog\ProductApprovalService;
use Database\Seeders\SystemLookupSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

class AdminApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected User $admin;

    protected Category $category;

    protected Unit $unit;

    protected SalesChannel $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        Cache::flush();

        $this->seller = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Queue Store',
            'store_slug' => 'queue-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin'])->id,
            'is_active' => true,
        ]);

        $this->category = Category::firstOrCreate(['slug' => 'fabrics'], ['name' => 'Fabrics', 'is_active' => true]);
        $this->unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $this->retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function product(string $title, ProductApprovalStatus $status = ProductApprovalStatus::PendingReview, float $price = 110): Product
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
            'is_active' => true,
        ]);
        $product->forceFill([
            'approval_status' => $status,
            'submitted_at' => $status === ProductApprovalStatus::PendingReview ? now()->subHours(3) : null,
        ])->save();
        $product->prices()->create(['sales_channel_id' => $this->retail->id, 'min_quantity' => 1, 'unit_price' => $price]);
        $product->ensureDefaultVariation()->update(['stock_quantity' => 5]);

        return $product->fresh();
    }

    private function propose(Product $product, float $price): ProductChangeRequest
    {
        NotificationFacade::fake();
        $prices = PriceSet::fromInput([
            ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => $price, 'min_quantity' => 1, 'max_quantity' => null],
        ], 500);

        return app(ProductApprovalService::class)->proposeChange(
            $product,
            $this->seller,
            $prices,
            $product->sales_channel_id,
            $product->category_id,
            null,
        );
    }

    public function test_a_reviewer_sees_both_queues_with_badge_counts(): void
    {
        $waiting = $this->product('Waiting Shirt');
        $this->product('Draft Shirt', ProductApprovalStatus::Draft);
        $live = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($live, 120);

        $this->assertTrue(ProductApprovalResource::canAccess());
        $this->assertTrue(ChangeRequestApprovalResource::canAccess());
        $this->assertSame('1', ProductApprovalResource::getNavigationBadge());
        $this->assertSame('1', ChangeRequestApprovalResource::getNavigationBadge());

        Livewire::test(ListProductApprovals::class)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$live])
            ->assertSee('Queue Store');

        Livewire::test(ListChangeRequestApprovals::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee('Live Shirt');
    }

    public function test_the_badges_are_null_when_nothing_waits(): void
    {
        $this->assertNull(ProductApprovalResource::getNavigationBadge());
        $this->assertNull(ChangeRequestApprovalResource::getNavigationBadge());
    }

    public function test_sellers_and_customers_cannot_open_the_queues(): void
    {
        $customer = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer'])->id,
            'is_active' => true,
        ]);

        foreach ([$this->seller, $customer] as $user) {
            $this->actingAs($user);
            $this->assertFalse(ProductApprovalResource::canAccess());
            $this->assertFalse(ChangeRequestApprovalResource::canAccess());
            $this->get(ProductApprovalResource::getUrl(panel: 'admin'))->assertForbidden();
            $this->get(ChangeRequestApprovalResource::getUrl(panel: 'admin'))->assertForbidden();
        }
    }

    public function test_the_review_gate_is_for_reviewers_only(): void
    {
        $this->assertTrue(Gate::forUser($this->admin)->allows('review-product'));
        $this->assertFalse(Gate::forUser($this->seller)->allows('review-product'));
    }

    public function test_approving_a_product_from_the_queue_makes_it_live(): void
    {
        NotificationFacade::fake();
        $product = $this->product('Waiting Shirt');

        Livewire::test(ListProductApprovals::class)
            ->callAction(TestAction::make('approve')->table($product))
            ->assertNotified();

        $product->refresh();
        $this->assertSame(ProductApprovalStatus::Approved, $product->approval_status);
        $this->assertSame($this->admin->id, $product->approved_by_user_id);
    }

    public function test_rejecting_a_product_stores_the_reason(): void
    {
        NotificationFacade::fake();
        $product = $this->product('Waiting Shirt');

        Livewire::test(ListProductApprovals::class)
            ->callAction(TestAction::make('reject')->table($product), [
                'reason' => '  The photos are blurry, please upload clearer ones.  ',
            ])
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame(ProductApprovalStatus::Rejected, $product->approval_status);
        $this->assertSame('The photos are blurry, please upload clearer ones.', $product->rejection_reason);
    }

    public function test_a_short_reject_reason_fails_validation(): void
    {
        $product = $this->product('Waiting Shirt');

        Livewire::test(ListProductApprovals::class)
            ->callAction(TestAction::make('reject')->table($product), ['reason' => 'short'])
            ->assertHasFormErrors(['reason']);

        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
    }

    public function test_a_padded_reason_is_measured_after_trimming(): void
    {
        $product = $this->product('Waiting Shirt');

        Livewire::test(ListProductApprovals::class)
            ->callAction(TestAction::make('reject')->table($product), ['reason' => '   short    '])
            ->assertHasFormErrors(['reason']);
    }

    public function test_bulk_approve_approves_every_selected_product(): void
    {
        NotificationFacade::fake();
        $a = $this->product('Shirt A');
        $b = $this->product('Shirt B');

        Livewire::test(ListProductApprovals::class)
            ->selectTableRecords([$a->getKey(), $b->getKey()])
            ->callAction(TestAction::make('approveSelected')->table()->bulk())
            ->assertNotified('2 approved');

        $this->assertSame(ProductApprovalStatus::Approved, $a->fresh()->approval_status);
        $this->assertSame(ProductApprovalStatus::Approved, $b->fresh()->approval_status);
    }

    public function test_approving_a_product_edited_after_the_modal_opened_is_refused(): void
    {
        NotificationFacade::fake();
        $product = $this->product('Waiting Shirt');

        $component = Livewire::test(ListProductApprovals::class)
            ->mountAction(TestAction::make('approve')->table($product));

        $this->travel(5)->seconds();
        $product->forceFill(['title' => 'Waiting Shirt v2'])->save();

        $component->callMountedAction()->assertNotified(
            Notification::make()->title('Could not complete this')->body(ProductApprovalService::STALE_VERSION_MESSAGE)->danger()
        );
        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
    }

    public function test_approving_a_change_from_the_queue_replaces_the_prices(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($product, 125);
        NotificationFacade::fake();

        Livewire::test(ListChangeRequestApprovals::class)
            ->callAction(TestAction::make('approve')->table($request))
            ->assertNotified();

        $this->assertSame('125.00', $product->prices()->first()->unit_price);
        $this->assertSame(ProductChangeRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_approving_a_change_whose_prices_moved_shows_the_conflict(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($product, 125);
        $product->prices()->first()->update(['unit_price' => 111]);
        NotificationFacade::fake();

        Livewire::test(ListChangeRequestApprovals::class)
            ->callAction(TestAction::make('approve')->table($request))
            ->assertNotified(
                Notification::make()->title('Could not complete this')->body(ProductApprovalService::PRICES_CHANGED_REASON)->danger()
            );

        $this->assertSame('111.00', $product->prices()->first()->unit_price);
        $this->assertNotSame(ProductChangeRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_approving_a_change_replaced_by_a_newer_one_shows_the_conflict_and_leaves_prices_alone(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $old = $this->propose($product, 125);

        $component = Livewire::test(ListChangeRequestApprovals::class)
            ->mountAction(TestAction::make('approve')->table($old));

        $newer = $this->propose($product->fresh(), 130);
        NotificationFacade::fake();

        $component->callMountedAction()->assertNotified(
            Notification::make()->title('Could not complete this')->body(ProductApprovalService::SUPERSEDED_MESSAGE)->danger()
        );

        $this->assertSame('110.00', $product->prices()->first()->unit_price);
        $this->assertSame(ProductChangeRequest::STATUS_SUPERSEDED, $old->fresh()->status);
        $this->assertSame(ProductChangeRequest::STATUS_PENDING, $newer->fresh()->status);
    }

    public function test_rejecting_a_change_stores_the_reason(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($product, 125);
        NotificationFacade::fake();

        Livewire::test(ListChangeRequestApprovals::class)
            ->callAction(TestAction::make('reject')->table($request), ['reason' => 'Price jump is too large for this item.'])
            ->assertHasNoFormErrors();

        $this->assertSame(ProductChangeRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame('Price jump is too large for this item.', $request->fresh()->rejection_reason);
    }

    public function test_deleting_a_product_with_a_pending_change_keeps_the_queue_working(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($product, 125);
        $this->assertSame('1', ChangeRequestApprovalResource::getNavigationBadge());

        $product->delete();

        Livewire::test(ListChangeRequestApprovals::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords([$request]);
        $this->assertNull(ChangeRequestApprovalResource::getNavigationBadge());
        $this->assertDatabaseMissing('product_change_requests', ['id' => $request->id]);
    }

    public function test_the_never_reviewed_filter_lists_backfilled_products_only(): void
    {
        $backfilled = $this->product('Backfilled Shirt', ProductApprovalStatus::Approved);
        $reviewed = $this->product('Reviewed Shirt', ProductApprovalStatus::Approved);
        $reviewed->forceFill(['approved_by_user_id' => $this->admin->id])->save();
        $waiting = $this->product('Waiting Shirt');

        Livewire::test(ListModeratedProducts::class)
            ->filterTable('never_reviewed', true)
            ->assertCanSeeTableRecords([$backfilled])
            ->assertCanNotSeeTableRecords([$reviewed, $waiting]);
    }

    public function test_the_moderation_page_filters_by_review_status(): void
    {
        $waiting = $this->product('Waiting Shirt');
        $live = $this->product('Live Shirt', ProductApprovalStatus::Approved);

        Livewire::test(ListModeratedProducts::class)
            ->assertSee('Waiting for review')
            ->filterTable('approval_status', ProductApprovalStatus::PendingReview->value)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$live]);
    }

    public function test_the_change_view_shows_a_live_flash_campaign_offer(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($product, 125);
        $sale = FlashSale::factory()->live()->create(['name' => 'Eid Mega Sale']);
        FlashSaleProduct::factory()->create(['flash_sale_id' => $sale->id, 'product_id' => $product->id, 'seller_id' => $this->seller->id]);

        Livewire::test(ListChangeRequestApprovals::class)
            ->mountAction(TestAction::make('view')->table($request))
            ->assertActionMounted(TestAction::make('view')->table($request));

        $html = ChangeRequestReviewDetails::html($request->fresh())->toHtml();
        $this->assertStringContainsString('Eid Mega Sale', $html);
        $this->assertStringContainsString('live now', $html);
        $this->assertStringContainsString('৳110.00', $html);
        $this->assertStringContainsString('৳125.00', $html);
    }

    public function test_the_product_view_shows_seller_state_and_past_rejections(): void
    {
        $product = $this->product('Waiting Shirt');
        NotificationFacade::fake();
        $service = app(ProductApprovalService::class);
        $service->rejectProduct($product, $this->admin, 'The photos are blurry, please redo them.', $service->versionOf($product->fresh()));
        $product = $product->fresh();
        $product->forceFill(['approval_status' => ProductApprovalStatus::PendingReview, 'submitted_at' => now()])->save();

        Livewire::test(ListProductApprovals::class)
            ->mountAction(TestAction::make('view')->table($product->fresh()))
            ->assertActionMounted(TestAction::make('view')->table($product->fresh()));

        $html = ProductReviewDetails::html($product->fresh())->toHtml();
        $this->assertStringContainsString('The photos are blurry, please redo them.', $html);
        $this->assertStringContainsString('Queue Store', $html);
        $this->assertStringContainsString('Store approved', $html);
    }

    public function test_the_largest_change_is_flagged_past_the_thresholds(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($product, 125);
        $this->assertSame('+13.6%', ChangeRequestSummary::largestChange($request)['text']);
        $this->assertFalse(ChangeRequestSummary::largestChange($request)['alert']);

        $row = fn (string $price, int $min = 1): array => ['sales_channel_id' => $this->retail->id, 'variation_id' => null, 'unit_price' => $price, 'min_quantity' => $min, 'max_quantity' => null];

        $request->proposed_prices = [$row('135.00')];
        $this->assertSame('+22.7%', ChangeRequestSummary::largestChange($request)['text']);
        $this->assertTrue(ChangeRequestSummary::largestChange($request)['alert']);

        $request->proposed_prices = [$row('50.00')];
        $this->assertSame('-54.5%', ChangeRequestSummary::largestChange($request)['text']);
        $this->assertTrue(ChangeRequestSummary::largestChange($request)['alert']);

        $request->proposed_prices = [$row('55.00')];
        $this->assertFalse(ChangeRequestSummary::largestChange($request)['alert']);

        $request->proposed_prices = [$row('110.00'), $row('90.00', 10)];
        $this->assertSame('new', ChangeRequestSummary::largestChange($request)['text']);
    }

    public function test_the_admin_bell_notifications_use_the_filament_format(): void
    {
        $product = $this->product('Waiting Shirt');
        $data = (new ProductSubmittedForReview($product))->toDatabase($this->admin);
        $this->assertSame('New product waiting for review', $data['title']);
        $this->assertSame('filament', $data['format']);
        $this->assertNotEmpty($data['actions']);

        $request = $this->propose($this->product('Live Shirt', ProductApprovalStatus::Approved), 125);
        $data = (new ProductChangeSubmitted($request))->toDatabase($this->admin);
        $this->assertSame('Price change waiting for review', $data['title']);
        $this->assertSame('filament', $data['format']);
        $this->assertTrue(Filament::getPanel('admin')->hasDatabaseNotifications());
    }

    public function test_the_change_request_policy_follows_the_spec(): void
    {
        $product = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($product, 125);
        $other = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id,
            'is_active' => true,
        ]);

        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $request));
        $this->assertTrue(Gate::forUser($this->admin)->allows('review', $request));
        $this->assertFalse(Gate::forUser($this->admin)->allows('withdraw', $request));
        $this->assertTrue(Gate::forUser($this->seller)->allows('viewAny', ProductChangeRequest::class));
        $this->assertTrue(Gate::forUser($this->seller)->allows('view', $request));
        $this->assertTrue(Gate::forUser($this->seller)->allows('withdraw', $request));
        $this->assertFalse(Gate::forUser($this->seller)->allows('review', $request));
        $this->assertFalse(Gate::forUser($other)->allows('view', $request));
        $this->assertFalse(Gate::forUser($other)->allows('withdraw', $request));

        $request->forceFill(['status' => ProductChangeRequest::STATUS_APPROVED]);
        $this->assertFalse(Gate::forUser($this->seller)->allows('withdraw', $request));

        foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $this->assertFalse(Gate::forUser($this->admin)->allows($ability, $request), $ability);
        }
        $this->assertFalse(Gate::forUser($this->admin)->allows('create', ProductChangeRequest::class));
        $this->assertFalse(Gate::forUser($this->admin)->allows('deleteAny', ProductChangeRequest::class));
    }

    public function test_the_review_slide_overs_escape_seller_text(): void
    {
        $product = $this->product('<script>alert(1)</script> Shirt');
        $this->assertStringNotContainsString('<script>', ProductReviewDetails::html($product)->toHtml());

        $live = $this->product('Live Shirt', ProductApprovalStatus::Approved);
        $request = $this->propose($live, 125);
        $request->forceFill(['seller_note' => '<img src=x onerror=alert(1)>']);
        $this->assertStringNotContainsString('<img src=x', ChangeRequestReviewDetails::html($request)->toHtml());
    }

    public function test_the_approvals_navigation_parent_lists_both_queues(): void
    {
        $this->product('Waiting Shirt');

        $this->get('/admin')->assertOk()->assertSee('Approvals')->assertSee('New products')->assertSee('Price changes');
    }

    public function test_bulk_approve_skips_a_product_changed_after_the_confirmation_opened(): void
    {
        NotificationFacade::fake();
        $a = $this->product('Shirt A');
        $b = $this->product('Shirt B');

        $component = Livewire::test(ListProductApprovals::class)
            ->selectTableRecords([$a->getKey(), $b->getKey()])
            ->mountAction(TestAction::make('approveSelected')->table()->bulk());

        $this->travel(1)->seconds();
        $b->touch();

        $component->callMountedAction()->assertNotified('1 approved, 1 skipped (changed since)');

        $this->assertSame(ProductApprovalStatus::Approved, $a->fresh()->approval_status);
        $this->assertSame(ProductApprovalStatus::PendingReview, $b->fresh()->approval_status);
    }

    public function test_submitted_notifications_escape_seller_text(): void
    {
        $title = '<a href="https://evil.test">Verify</a>';
        $product = $this->product($title);
        $body = (new ProductSubmittedForReview($product))->toDatabase($this->admin)['body'];
        $this->assertStringNotContainsString('<a', $body);

        $live = $this->product($title.' live', ProductApprovalStatus::Approved);
        $live->forceFill(['title' => $title])->save();
        $request = $this->propose($live->fresh(), 125);
        $body = (new ProductChangeSubmitted($request))->toDatabase($this->admin)['body'];
        $this->assertStringNotContainsString('<a', $body);
    }

    public function test_the_list_pages_are_forbidden_to_sellers_and_customers(): void
    {
        $customer = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'customer'], ['name' => 'Customer'])->id,
            'is_active' => true,
        ]);

        foreach ([$this->seller, $customer] as $user) {
            $this->actingAs($user);
            Livewire::test(ListProductApprovals::class)->assertForbidden();
            Livewire::test(ListChangeRequestApprovals::class)->assertForbidden();
        }
    }

    public function test_the_submissions_column_counts_product_submitted_audit_rows(): void
    {
        $product = $this->product('Waiting Shirt');
        foreach ([1, 2] as $_) {
            AuditLog::create([
                'module' => 'approvals', 'action' => 'product_submitted', 'description' => 'x',
                'auditable_type' => Product::class, 'auditable_id' => $product->id,
            ]);
        }

        Livewire::test(ListProductApprovals::class)
            ->assertTableColumnStateSet('submissions_count', 2, $product);
    }

    public function test_the_approve_modal_shows_the_product_it_approves(): void
    {
        $product = $this->product('Waiting Shirt');
        $product->forceFill(['short_description' => 'Hand-loomed cotton, forty count'])->save();

        Livewire::test(ListProductApprovals::class)
            ->mountAction(TestAction::make('approve')->table($product))
            ->assertMountedActionModalSee('Waiting Shirt')
            ->assertMountedActionModalSee('Hand-loomed cotton, forty count')
            ->assertMountedActionModalSee('Queue Store');
    }

    public function test_the_reject_modal_shows_the_product_it_rejects(): void
    {
        $product = $this->product('Waiting Shirt');
        $product->forceFill(['short_description' => 'Hand-loomed cotton, forty count'])->save();

        Livewire::test(ListProductApprovals::class)
            ->mountAction(TestAction::make('reject')->table($product))
            ->assertMountedActionModalSee('Hand-loomed cotton, forty count');
    }

    public function test_approving_after_the_product_is_touched_a_second_later_is_refused(): void
    {
        NotificationFacade::fake();
        $product = $this->product('Waiting Shirt');

        $component = Livewire::test(ListProductApprovals::class)
            ->mountAction(TestAction::make('approve')->table($product));

        $this->travel(1)->seconds();
        $product->touch();

        $component->callMountedAction()->assertNotified(
            Notification::make()->title('Could not complete this')->body(ProductApprovalService::STALE_VERSION_MESSAGE)->danger()
        );
        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
    }

    public function test_the_review_slide_over_can_approve_from_its_footer(): void
    {
        NotificationFacade::fake();
        $product = $this->product('Waiting Shirt');

        Livewire::test(ListProductApprovals::class)
            ->mountAction([TestAction::make('view')->table($product), TestAction::make('approve')])
            ->assertMountedActionModalSee('Waiting Shirt')
            ->callMountedAction()
            ->assertNotified('Product approved');

        $this->assertSame(ProductApprovalStatus::Approved, $product->fresh()->approval_status);
    }

    public function test_the_review_slide_over_can_reject_from_its_footer(): void
    {
        NotificationFacade::fake();
        $product = $this->product('Waiting Shirt');

        Livewire::test(ListProductApprovals::class)
            ->mountAction([TestAction::make('view')->table($product), TestAction::make('reject')])
            ->setActionData(['reason' => 'The photos are blurry, please upload clearer ones.'])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Product not approved');

        $this->assertSame(ProductApprovalStatus::Rejected, $product->fresh()->approval_status);
    }
}
