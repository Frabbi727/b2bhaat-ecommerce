<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Exceptions\ApprovalConflictException;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Catalog\ProductReviewDecided;
use App\Notifications\Catalog\ProductSubmittedForReview;
use App\Services\Catalog\ProductApprovalService;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductApprovalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $admin;

    private User $superAdmin;

    private User $customer;

    private ProductApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        Notification::fake();
        Cache::flush();

        $this->seller = $this->userWithRole('seller');
        SellerProfile::create([
            'user_id' => $this->seller->id,
            'store_name' => 'Lifecycle Store',
            'store_slug' => 'lifecycle-store',
            'monetization_status' => 'active',
            'is_approved' => true,
        ]);
        $this->admin = $this->userWithRole('admin');
        $this->superAdmin = $this->userWithRole('super_admin');
        $this->customer = $this->userWithRole('customer');
        $this->service = app(ProductApprovalService::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)])->id,
            'is_active' => true,
        ]);
    }

    private function product(string $status = 'draft', ?User $owner = null, string $title = 'Cotton Fabric'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'fabrics'], ['name' => 'Fabrics', 'is_active' => true]);
        $unit = Unit::firstOrCreate(['slug' => 'pcs'], ['name' => 'Pieces', 'symbol' => 'pcs']);
        $retail = SalesChannel::firstOrCreate(['slug' => SalesChannel::RETAIL], ['name' => 'Retail', 'is_active' => true]);

        $product = Product::create([
            'seller_id' => ($owner ?? $this->seller)->id,
            'category_id' => $category->id,
            'sales_channel_id' => $retail->id,
            'unit_id' => $unit->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString().'-'.uniqid(),
            'regular_price' => 500,
            'has_variations' => false,
            'is_active' => true,
        ]);
        $product->prices()->create(['sales_channel_id' => $retail->id, 'min_quantity' => 1, 'unit_price' => 500]);
        $product->ensureDefaultVariation();
        $product->forceFill(['approval_status' => $status])->save();

        return $product->fresh();
    }

    public function test_submit_moves_draft_to_waiting_and_notifies_admins_once_per_hour(): void
    {
        $product = $this->product();

        $submitted = $this->service->submitProduct($product, $this->seller);

        $this->assertSame(ProductApprovalStatus::PendingReview, $submitted->approval_status);
        $this->assertNotNull($submitted->submitted_at);
        Notification::assertSentTo($this->admin, ProductSubmittedForReview::class);
        Notification::assertSentTo($this->superAdmin, ProductSubmittedForReview::class);
        Notification::assertNotSentTo($this->customer, ProductSubmittedForReview::class);

        $this->service->withdrawProduct($submitted, $this->seller);
        $this->assertSame(ProductApprovalStatus::Draft, $product->fresh()->approval_status);
        $this->service->submitProduct($product->fresh(), $this->seller);

        Notification::assertSentToTimes($this->admin, ProductSubmittedForReview::class, 1);
        Notification::assertSentToTimes($this->superAdmin, ProductSubmittedForReview::class, 1);
    }

    public function test_submit_is_a_no_op_for_waiting_and_approved_products(): void
    {
        $waiting = $this->product('pending_review');
        $approved = $this->product('approved');

        $this->service->submitProduct($waiting, $this->seller);
        $this->service->submitProduct($approved, $this->seller);

        $this->assertSame(ProductApprovalStatus::PendingReview, $waiting->fresh()->approval_status);
        $this->assertSame(ProductApprovalStatus::Approved, $approved->fresh()->approval_status);
        $this->assertSame(0, AuditLog::where('module', 'approvals')->count());
    }

    public function test_withdraw_outside_waiting_state_is_a_conflict(): void
    {
        $product = $this->product('approved');

        $this->expectException(ApprovalConflictException::class);
        $this->expectExceptionMessage('This product is no longer waiting for review.');
        $this->service->withdrawProduct($product, $this->seller);
    }

    public function test_approve_sets_reviewer_and_notifies_seller_by_database_and_mail(): void
    {
        $product = $this->product('pending_review');

        $approved = $this->service->approveProduct($product, $this->admin, $this->service->versionOf($product));

        $this->assertSame(ProductApprovalStatus::Approved, $approved->approval_status);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame($this->admin->id, $approved->approved_by_user_id);
        $this->assertSame($this->admin->id, $approved->reviewed_by_user_id);
        Notification::assertSentTo($this->seller, ProductReviewDecided::class, function (ProductReviewDecided $n) {
            $mail = $n->toMail($this->seller);

            return $n->via($this->seller) === ['database', 'mail']
                && $mail->subject === 'Your product was approved'
                && $n->toArray($this->seller)['approved'] === true;
        });
    }

    public function test_approve_keeps_an_existing_approved_at(): void
    {
        $product = $this->product('pending_review');
        $first = now()->subYear()->startOfSecond();
        $product->forceFill(['approved_at' => $first])->save();
        $product = $product->fresh();

        $approved = $this->service->approveProduct($product, $this->admin, $this->service->versionOf($product));

        $this->assertTrue($approved->approved_at->equalTo($first));
    }

    public function test_reject_requires_a_real_reason(): void
    {
        $product = $this->product('pending_review');

        try {
            $this->service->rejectProduct($product, $this->admin, '   no  ', $this->service->versionOf($product));
            $this->fail('Expected a DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('Please give a reason of at least 10 characters so the seller knows what to fix.', $e->getMessage());
        }

        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);

        $this->expectException(DomainException::class);
        $this->service->rejectProduct($product, $this->admin, str_repeat('a', 1001), $this->service->versionOf($product));
    }

    public function test_reject_stores_reason_and_notifies_seller(): void
    {
        $product = $this->product('pending_review');

        $rejected = $this->service->rejectProduct($product, $this->admin, '  Photos are too blurry to review.  ', $this->service->versionOf($product));

        $this->assertSame(ProductApprovalStatus::Rejected, $rejected->approval_status);
        $this->assertSame('Photos are too blurry to review.', $rejected->rejection_reason);
        $this->assertNotNull($rejected->rejected_at);
        Notification::assertSentTo($this->seller, ProductReviewDecided::class, function (ProductReviewDecided $n) {
            return $n->toMail($this->seller)->subject === 'Your product was not approved';
        });
    }

    public function test_approve_or_reject_outside_waiting_state_is_a_conflict(): void
    {
        $product = $this->product('draft');

        $this->expectException(ApprovalConflictException::class);
        $this->expectExceptionMessage('This product is no longer waiting for review.');
        $this->service->approveProduct($product, $this->admin, 'stale-but-state-wins');
    }

    public function test_bengali_reason_and_long_title_are_kept_intact(): void
    {
        $title = str_repeat('অত্যন্ত দীর্ঘ পণ্যের নাম ', 10);
        $product = $this->product('pending_review', null, $title);
        $reason = 'ছবিগুলো পরিষ্কার নয়, অনুগ্রহ করে নতুন ছবি দিন। Price too low & "odd" <b>';

        $rejected = $this->service->rejectProduct($product, $this->admin, $reason, $this->service->versionOf($product));

        $this->assertSame($reason, $rejected->rejection_reason);
        Notification::assertSentTo($this->seller, ProductReviewDecided::class, function (ProductReviewDecided $n) use ($reason, $title) {
            $payload = $n->toArray($this->seller);

            return $payload['reason'] === $reason
                && $payload['title'] === $title
                && $payload['approved'] === false
                && str_contains(implode("\n", $n->toMail($this->seller)->introLines), $reason);
        });
    }

    public function test_stale_version_is_refused(): void
    {
        $product = $this->product('pending_review');
        $version = $this->service->versionOf($product);

        $this->travel(1)->seconds();
        $product->touch();

        try {
            $this->service->approveProduct($product->fresh(), $this->admin, $version);
            $this->fail('Expected a conflict.');
        } catch (ApprovalConflictException $e) {
            $this->assertSame('This product was changed by the seller after you opened it. Please review the latest version.', $e->getMessage());
        }

        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
    }

    public function test_resubmitting_a_rejected_product_clears_the_reason(): void
    {
        $product = $this->product('pending_review');
        $this->service->rejectProduct($product, $this->admin, 'Please add a clearer description.', $this->service->versionOf($product));

        $resubmitted = $this->service->submitProduct($product->fresh(), $this->seller);

        $this->assertSame(ProductApprovalStatus::PendingReview, $resubmitted->approval_status);
        $this->assertNull($resubmitted->rejection_reason);
        $this->assertNull($resubmitted->rejected_at);
    }

    public function test_approving_while_the_seller_is_suspended_keeps_it_hidden(): void
    {
        $product = $this->product('pending_review');
        $profile = $this->seller->sellerProfile;
        $profile->forceFill(['monetization_status' => 'suspended'])->save();

        $approved = $this->service->approveProduct($product, $this->admin, $this->service->versionOf($product));

        $this->assertSame(ProductApprovalStatus::Approved, $approved->approval_status);
        $this->assertFalse(Product::sellable()->whereKey($product->id)->exists());

        $profile->forceFill(['monetization_status' => 'active'])->save();
        $this->assertTrue(Product::sellable()->whereKey($product->id)->exists());
    }

    public function test_a_seller_cannot_approve_and_cannot_submit_another_sellers_product(): void
    {
        $other = $this->userWithRole('seller');
        $theirs = $this->product('draft', $other);
        $mine = $this->product('pending_review');

        try {
            $this->service->submitProduct($theirs, $this->seller);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertSame(ProductApprovalStatus::Draft, $theirs->fresh()->approval_status);
        }

        $this->expectException(AuthorizationException::class);
        $this->service->approveProduct($mine, $this->seller, $this->service->versionOf($mine));
    }

    public function test_each_transition_writes_one_approvals_audit_row(): void
    {
        $a = $this->product();
        $this->service->submitProduct($a, $this->seller);
        $this->service->withdrawProduct($a->fresh(), $this->seller);
        $this->service->submitProduct($a->fresh(), $this->seller);
        $this->service->approveProduct($a->fresh(), $this->admin, $this->service->versionOf($a->fresh()));

        $b = $this->product();
        $this->service->submitProduct($b, $this->seller);
        $this->service->rejectProduct($b->fresh(), $this->admin, 'Description is missing details.', $this->service->versionOf($b->fresh()));

        foreach (['product_submitted' => 3, 'product_withdrawn' => 1, 'product_approved' => 1, 'product_rejected' => 1] as $action => $count) {
            $this->assertSame($count, AuditLog::where('module', 'approvals')->where('action', $action)->count(), $action);
        }

        $row = AuditLog::where('action', 'product_rejected')->firstOrFail();
        $this->assertSame($this->seller->id, $row->seller_id);
        $this->assertSame($b->id, $row->metadata['product_id']);
        $this->assertSame('Description is missing details.', $row->metadata['reason']);
        $this->assertArrayHasKey('version', $row->metadata);
    }

    public function test_audit_prune_keeps_approvals_rows(): void
    {
        $old = AuditLog::create([
            'module' => 'approvals',
            'action' => 'product_approved',
            'description' => 'Old approval',
            'created_at' => now()->subDays(400),
        ]);

        Artisan::call('audit:prune', ['--force' => true]);

        $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);
    }

    public function test_a_failing_admin_notification_does_not_undo_the_submission(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('mail down'));
        $product = $this->product();

        $submitted = $this->service->submitProduct($product, $this->seller);

        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
        $this->assertSame(ProductApprovalStatus::PendingReview, $submitted->approval_status);
        $this->assertFalse(Cache::has("approval-notify:{$product->id}"));
    }

    public function test_a_failing_seller_notification_does_not_undo_the_decision(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('mail down'));
        $product = $this->product('pending_review');

        $this->service->approveProduct($product, $this->admin, $this->service->versionOf($product));

        $this->assertSame(ProductApprovalStatus::Approved, $product->fresh()->approval_status);
    }

    public function test_decision_mail_links_to_the_seller_edit_page(): void
    {
        $product = $this->product('pending_review');
        $mail = (new ProductReviewDecided($product, false, 'Photos are too blurry.'))->toMail($this->seller);

        $this->assertStringContainsString(
            ProductResource::getUrl('edit', ['record' => $product], panel: 'seller'),
            $mail->actionUrl,
        );
    }

    public function test_decision_notification_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ProductReviewDecided($this->product(), true));
    }

    public function test_reject_with_a_stale_version_is_refused(): void
    {
        $product = $this->product('pending_review');
        $version = $this->service->versionOf($product);
        $this->travel(1)->seconds();
        $product->touch();

        try {
            $this->service->rejectProduct($product->fresh(), $this->admin, 'Photos are too blurry.', $version);
            $this->fail('Expected a conflict.');
        } catch (ApprovalConflictException $e) {
            $this->assertSame('This product was changed by the seller after you opened it. Please review the latest version.', $e->getMessage());
        }

        $this->assertSame(ProductApprovalStatus::PendingReview, $product->fresh()->approval_status);
    }
}
