<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductApprovalStatus;
use App\Exceptions\ApprovalConflictException;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Catalog\ProductReviewDecided;
use App\Notifications\Catalog\ProductSubmittedForReview;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The only class that changes a product's approval state (spec §6.1).
 */
class ProductApprovalService
{
    public const STALE_VERSION_MESSAGE = 'This product was changed by the seller after you opened it. Please review the latest version.';

    public const WRONG_STATE_MESSAGE = 'This product is no longer waiting for review.';

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
            $reviewers = User::query()->whereHas('role', fn ($q) => $q->whereIn('slug', ['super_admin', 'admin']))
                ->where('is_active', true)->get();

            try {
                Notification::send($reviewers, new ProductSubmittedForReview($locked));
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

        $reason = trim($reason);
        $length = mb_strlen($reason);
        if ($length < 10 || $length > 1000) {
            throw new DomainException(self::REASON_MESSAGE);
        }

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
