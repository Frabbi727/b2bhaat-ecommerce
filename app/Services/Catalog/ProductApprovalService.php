<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Exceptions\ApprovalConflictException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\ProductPrice;
use App\Models\SalesChannel;
use App\Models\User;
use App\Notifications\Catalog\ProductChangeDecided;
use App\Notifications\Catalog\ProductChangeSubmitted;
use App\Notifications\Catalog\ProductReviewDecided;
use App\Notifications\Catalog\ProductSubmittedForReview;
use App\Services\Audit\AuditService;
use App\Services\Promotions\FlashSalePricing;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The only class that changes a product's approval state (spec §6.1).
 */
class ProductApprovalService
{
    public const STALE_VERSION_MESSAGE = 'This product was changed by the seller after you opened it. Please review the latest version.';

    public const WRONG_STATE_MESSAGE = 'This product is no longer waiting for review.';

    public const SUPERSEDED_MESSAGE = 'The seller has sent a newer change; please review that one.';

    public const CHANGE_WRONG_STATE_MESSAGE = 'This change is no longer waiting for review.';

    public const CATEGORY_UNAVAILABLE_MESSAGE = 'That category is no longer available';

    public const PRICES_CHANGED_REASON = 'Prices changed since this was submitted; please submit again';

    public const RATE_LIMIT_MESSAGE = "You've sent many price changes in the last hour. Please wait a little and try again.";

    public const FOREIGN_ROWS_MESSAGE = 'Some price rows point to an option or sales channel that does not belong to this product.';

    public const CHANNEL_UNAVAILABLE_MESSAGE = 'That sales channel is no longer available';

    public const MAX_CHANGES_PER_HOUR = 30;

    public const REASON_MESSAGE = 'Please give a reason of at least 10 characters so the seller knows what to fix.';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * The version a reviewer captures when opening a product; precise enough to notice edits within a second.
     * Callers must take the version from a freshly loaded row, never from a stale in-memory model.
     */
    public function versionOf(Product $product): string
    {
        return $product->updated_at->format('Y-m-d\TH:i:s.uP');
    }

    public function submitProduct(Product $product, User $seller): Product
    {
        $this->assertOwner($product, $seller);
        $notifyAdmins = false;

        $locked = DB::transaction(function () use ($product, $seller, &$notifyAdmins): Product {
            $locked = $this->lock($product);
            $this->assertOwner($locked, $seller);
            $status = $locked->approval_status;

            if ($status === ProductApprovalStatus::PendingReview || $status === ProductApprovalStatus::Approved) {
                return $locked;
            }

            $locked->forceFill([
                'approval_status' => ProductApprovalStatus::PendingReview,
                'submitted_at' => now(),
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $this->audit($locked, $seller, 'product_submitted', 'sent for review');
            $notifyAdmins = true;

            return $locked;
        });

        if ($notifyAdmins && Cache::add("approval-notify:{$locked->id}", true, 3600)) {
            try {
                Notification::send($this->reviewers(), new ProductSubmittedForReview($locked));
            } catch (Throwable $e) {
                Cache::forget("approval-notify:{$locked->id}");
                report($e);
            }
        }

        return $locked;
    }

    public function withdrawProduct(Product $product, User $seller): Product
    {
        $this->assertOwner($product, $seller);

        return DB::transaction(function () use ($product, $seller): Product {
            $locked = $this->lock($product);
            $this->assertOwner($locked, $seller);
            $this->assertWaiting($locked);

            $locked->forceFill(['approval_status' => ProductApprovalStatus::Draft])->save();
            $this->audit($locked, $seller, 'product_withdrawn', 'withdrawn from review');

            return $locked;
        });
    }

    public function approveProduct(Product $product, User $admin, string $expectedVersion): Product
    {
        $this->assertReviewer($admin);

        $locked = DB::transaction(function () use ($product, $admin, $expectedVersion): Product {
            $locked = $this->lock($product);
            $this->assertWaiting($locked);
            $this->assertVersion($locked, $expectedVersion);

            $locked->forceFill([
                'approval_status' => ProductApprovalStatus::Approved,
                'approved_at' => $locked->approved_at ?? now(),
                'approved_by_user_id' => $admin->id,
                'reviewed_by_user_id' => $admin->id,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $this->audit($locked, $admin, 'product_approved', 'approved', ['version' => $expectedVersion]);

            return $locked;
        });

        $this->notifySeller($locked, true, null);

        return $locked;
    }

    public function rejectProduct(Product $product, User $admin, string $reason, string $expectedVersion): Product
    {
        $this->assertReviewer($admin);

        $reason = $this->cleanReason($reason);

        $locked = DB::transaction(function () use ($product, $admin, $reason, $expectedVersion): Product {
            $locked = $this->lock($product);
            $this->assertWaiting($locked);
            $this->assertVersion($locked, $expectedVersion);

            $locked->forceFill([
                'approval_status' => ProductApprovalStatus::Rejected,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'reviewed_by_user_id' => $admin->id,
            ])->save();

            $this->audit($locked, $admin, 'product_rejected', 'rejected', ['version' => $expectedVersion, 'reason' => $reason]);

            return $locked;
        });

        $this->notifySeller($locked, false, $reason);

        return $locked;
    }

    /**
     * Hold a seller's new prices, sales channel or category for an approved product until an admin decides.
     * Returns null when the proposal equals what is live (a waiting request is then withdrawn).
     */
    public function proposeChange(Product $product, User $seller, PriceSet $prices, int $salesChannelId, int $categoryId, ?string $note): ?ProductChangeRequest
    {
        $this->assertOwner($product, $seller);
        $this->assertApproved($product);

        $limiterKey = "product-change:{$seller->id}";
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_CHANGES_PER_HOUR)) {
            throw new DomainException(self::RATE_LIMIT_MESSAGE);
        }

        $channel = SalesChannel::query()->whereKey($salesChannelId)->where('is_active', true)->first();
        if ($channel === null) {
            throw new DomainException(self::CHANNEL_UNAVAILABLE_MESSAGE);
        }

        $errors = $prices->validate($channel);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        $note = $note === null ? null : mb_substr(trim($note), 0, 500);
        $note = $note === '' ? null : $note;

        $request = DB::transaction(function () use ($product, $seller, $prices, $salesChannelId, $categoryId, $note): ?ProductChangeRequest {
            $locked = $this->lock($product);
            $this->assertOwner($locked, $seller);
            $this->assertApproved($locked);

            if ($categoryId !== $locked->category_id && ! $this->categoryIsActive($categoryId)) {
                throw new DomainException(self::CATEGORY_UNAVAILABLE_MESSAGE);
            }

            if (! $this->rowIdsBelongTo($prices, $locked)) {
                throw new DomainException(self::FOREIGN_ROWS_MESSAGE);
            }

            $live = PriceSet::fromProduct($locked);
            $pending = ProductChangeRequest::query()
                ->where('product_id', $locked->id)
                ->where('status', ProductChangeRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($live->equals($prices) && $salesChannelId === $locked->sales_channel_id && $categoryId === $locked->category_id) {
                if ($pending !== null) {
                    $pending->forceFill(['status' => ProductChangeRequest::STATUS_WITHDRAWN])->save();
                    $this->audit($locked, $seller, 'price_change_withdrawn', 'price change withdrawn (back to the current prices)', ['request_id' => $pending->id]);
                }

                return null;
            }

            $pending?->forceFill(['status' => ProductChangeRequest::STATUS_SUPERSEDED])->save();

            $request = new ProductChangeRequest([
                'product_id' => $locked->id,
                'seller_id' => $locked->seller_id,
                'proposed_prices' => $prices->rows(),
                'proposed_regular_price' => $prices->regularPrice(),
                'base_prices' => $live->rows(),
                'base_regular_price' => $live->regularPrice(),
                'base_sales_channel_id' => $locked->sales_channel_id,
                'base_category_id' => $locked->category_id,
                'base_fingerprint' => $live->fingerprint($locked->sales_channel_id, $locked->category_id),
                'seller_note' => $note,
                'submitted_by_user_id' => $seller->id,
            ]);
            $request->forceFill([
                'status' => ProductChangeRequest::STATUS_PENDING,
                'proposed_sales_channel_id' => $salesChannelId !== $locked->sales_channel_id ? $salesChannelId : null,
                'proposed_category_id' => $categoryId !== $locked->category_id ? $categoryId : null,
            ])->save();

            if ($pending !== null) {
                $pending->forceFill(['superseded_by_id' => $request->id])->save();
                $this->audit($locked, $seller, 'price_change_superseded', 'price change replaced by a newer one', [
                    'request_id' => $pending->id,
                    'superseded_by_id' => $request->id,
                ]);
            }

            $this->audit($locked, $seller, 'price_change_submitted', 'price change sent for review', [
                'request_id' => $request->id,
                'old_prices' => ['rows' => $live->rows(), 'regular_price' => $live->regularPrice(), 'sales_channel_id' => $locked->sales_channel_id, 'category_id' => $locked->category_id],
                'new_prices' => ['rows' => $prices->rows(), 'regular_price' => $prices->regularPrice(), 'sales_channel_id' => $salesChannelId, 'category_id' => $categoryId],
            ]);

            return $request;
        });

        if ($request === null) {
            return null;
        }

        RateLimiter::hit($limiterKey, 3600);

        if (Cache::add("approval-notify:{$request->product_id}", true, 3600)) {
            try {
                Notification::send($this->reviewers(), new ProductChangeSubmitted($request));
            } catch (Throwable $e) {
                Cache::forget("approval-notify:{$request->product_id}");
                report($e);
            }
        }

        return $request;
    }

    public function withdrawChange(ProductChangeRequest $request, User $seller): ProductChangeRequest
    {
        if ($request->seller_id !== $seller->id) {
            throw new AuthorizationException('You can only manage your own products.');
        }

        return DB::transaction(function () use ($request, $seller): ProductChangeRequest {
            $product = Product::query()->whereKey($request->product_id)->lockForUpdate()->firstOrFail();
            $locked = ProductChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->seller_id !== $seller->id) {
                throw new AuthorizationException('You can only manage your own products.');
            }
            $this->assertChangePending($locked);

            $locked->forceFill(['status' => ProductChangeRequest::STATUS_WITHDRAWN])->save();
            $this->audit($product, $seller, 'price_change_withdrawn', 'price change withdrawn', ['request_id' => $locked->id]);

            return $locked;
        });
    }

    /**
     * Apply a held change. A request whose base no longer matches the live prices is rejected, never applied.
     */
    public function approveChange(ProductChangeRequest $request, User $admin): ProductChangeRequest
    {
        $this->assertReviewer($admin);
        $conflict = null;

        $locked = DB::transaction(function () use ($request, $admin, &$conflict): ProductChangeRequest {
            $product = Product::query()->whereKey($request->product_id)->lockForUpdate()->firstOrFail();
            $locked = ProductChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->assertChangePending($locked);
            $this->assertApproved($product);

            $live = PriceSet::fromProduct($product);
            if (! hash_equals($locked->base_fingerprint, $live->fingerprint($product->sales_channel_id, $product->category_id))) {
                $locked->forceFill([
                    'status' => ProductChangeRequest::STATUS_REJECTED,
                    'rejection_reason' => self::PRICES_CHANGED_REASON,
                    'reviewed_by_user_id' => $admin->id,
                    'reviewed_at' => now(),
                ])->save();
                $this->audit($product, $admin, 'price_change_conflict', 'price change refused: prices changed since it was submitted', [
                    'request_id' => $locked->id,
                    'reason' => self::PRICES_CHANGED_REASON,
                ]);
                $conflict = self::PRICES_CHANGED_REASON;

                return $locked;
            }

            $channelId = $locked->proposed_sales_channel_id ?? $product->sales_channel_id;
            $categoryId = $locked->proposed_category_id ?? $product->category_id;
            $proposed = PriceSet::fromInput($locked->proposed_prices, $locked->proposed_regular_price);

            $channel = SalesChannel::query()->whereKey($channelId)->where('is_active', true)->first();
            if ($channel === null) {
                throw new ApprovalConflictException(self::CHANNEL_UNAVAILABLE_MESSAGE);
            }

            if (! $this->rowIdsBelongTo($proposed, $product)) {
                throw new ApprovalConflictException(self::FOREIGN_ROWS_MESSAGE);
            }

            $errors = $proposed->validate($channel);
            if ($errors !== []) {
                throw new ApprovalConflictException(implode(' ', $errors));
            }

            if ($locked->proposed_category_id !== null && ! $this->categoryIsActive($locked->proposed_category_id)) {
                throw new ApprovalConflictException(self::CATEGORY_UNAVAILABLE_MESSAGE);
            }

            $product->prices()->get()->each(fn (ProductPrice $price) => $price->delete());
            foreach ($proposed->rows() as $row) {
                $product->prices()->create($row);
            }

            $product->forceFill([
                'regular_price' => $proposed->regularPrice(),
                'sales_channel_id' => $channelId,
                'category_id' => $categoryId,
            ])->save();

            $appliedAt = now();
            $locked->forceFill([
                'status' => ProductChangeRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => $appliedAt,
            ])->save();
            $this->audit($product, $admin, 'price_change_approved', 'price change approved', [
                'request_id' => $locked->id,
                'applied_at' => $appliedAt->toIso8601String(),
            ]);

            return $locked;
        });

        if ($conflict !== null) {
            $this->notifyChangeSeller($locked, false, $conflict);

            throw new ApprovalConflictException($conflict);
        }

        app(FlashSalePricing::class)->flush();
        FlashSalePricing::flushResolved();

        $this->notifyChangeSeller($locked, true, null);

        return $locked;
    }

    public function rejectChange(ProductChangeRequest $request, User $admin, string $reason): ProductChangeRequest
    {
        $this->assertReviewer($admin);
        $reason = $this->cleanReason($reason);

        $locked = DB::transaction(function () use ($request, $admin, $reason): ProductChangeRequest {
            $product = Product::query()->whereKey($request->product_id)->lockForUpdate()->firstOrFail();
            $locked = ProductChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $this->assertChangePending($locked);

            $locked->forceFill([
                'status' => ProductChangeRequest::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ])->save();
            $this->audit($product, $admin, 'price_change_rejected', 'price change rejected', ['request_id' => $locked->id, 'reason' => $reason]);

            return $locked;
        });

        $this->notifyChangeSeller($locked, false, $reason);

        return $locked;
    }

    private function assertApproved(Product $product): void
    {
        if ($product->approval_status !== ProductApprovalStatus::Approved) {
            throw new ApprovalConflictException('Price changes can only be sent for products that are already approved.');
        }
    }

    private function assertChangePending(ProductChangeRequest $request): void
    {
        if ($request->status === ProductChangeRequest::STATUS_PENDING) {
            return;
        }

        throw new ApprovalConflictException(
            $request->status === ProductChangeRequest::STATUS_SUPERSEDED ? self::SUPERSEDED_MESSAGE : self::CHANGE_WRONG_STATE_MESSAGE
        );
    }

    /**
     * Every variation in the rows must be this product's, and every row's sales channel must exist.
     */
    private function rowIdsBelongTo(PriceSet $prices, Product $product): bool
    {
        $rows = collect($prices->rows());

        $variationIds = $rows->pluck('variation_id')->filter(fn ($id) => $id !== null)->unique();
        if ($variationIds->isNotEmpty()) {
            $own = $product->variations()->pluck('id');
            if ($variationIds->diff($own)->isNotEmpty()) {
                return false;
            }
        }

        $channelIds = $rows->pluck('sales_channel_id')->unique();

        return SalesChannel::query()->whereIn('id', $channelIds)->count() === $channelIds->count();
    }

    private function categoryIsActive(int $categoryId): bool
    {
        return Category::query()->whereKey($categoryId)->where('is_active', true)->exists();
    }

    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);
        if ($length < 10 || $length > 1000) {
            throw new DomainException(self::REASON_MESSAGE);
        }

        return $reason;
    }

    /**
     * @return Collection<int, User>
     */
    private function reviewers(): Collection
    {
        return User::query()->whereHas('role', fn ($q) => $q->whereIn('slug', ['super_admin', 'admin']))
            ->where('is_active', true)->get();
    }

    private function notifyChangeSeller(ProductChangeRequest $request, bool $approved, ?string $reason): void
    {
        $seller = User::query()->find($request->seller_id);

        if ($seller === null) {
            return;
        }

        try {
            Notification::send($seller, new ProductChangeDecided($request, $approved, $reason));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function lock(Product $product): Product
    {
        return Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertOwner(Product $product, User $seller): void
    {
        if ($product->seller_id !== $seller->id) {
            throw new AuthorizationException('You can only manage your own products.');
        }
    }

    private function assertReviewer(User $admin): void
    {
        if (! $admin->isCatalogReviewer()) {
            throw new AuthorizationException('Only an admin can review products.');
        }
    }

    private function assertWaiting(Product $product): void
    {
        if ($product->approval_status !== ProductApprovalStatus::PendingReview) {
            throw new ApprovalConflictException(self::WRONG_STATE_MESSAGE);
        }
    }

    private function assertVersion(Product $product, string $expectedVersion): void
    {
        if (! hash_equals($this->versionOf($product), $expectedVersion)) {
            throw new ApprovalConflictException(self::STALE_VERSION_MESSAGE);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(Product $product, User $actor, string $action, string $verb, array $extra = []): void
    {
        $this->audit->record([
            'module' => 'approvals',
            'action' => $action,
            'description' => "Product #{$product->id} {$verb}",
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'record_label' => $product->title,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'actor_role' => $actor->role?->slug,
            'seller_id' => $product->seller_id,
            'metadata' => ['product_id' => $product->id, 'seller_id' => $product->seller_id] + $extra,
            'status' => 'success',
            'sync' => true,
        ]);
    }

    private function notifySeller(Product $product, bool $approved, ?string $reason): void
    {
        $seller = User::query()->find($product->seller_id);

        if ($seller === null) {
            return;
        }

        try {
            Notification::send($seller, new ProductReviewDecided($product, $approved, $reason));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
