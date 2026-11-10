<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Product;
use App\Models\SubOrder;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $model->recordAuditActivity('created', null, $model->getAttributes());
        });

        static::updated(function (Model $model): void {
            $changed = $model->getChanges();
            $original = $model->getOriginal();

            $filteredChanged = [];
            $filteredOriginal = [];

            foreach ($changed as $key => $newValue) {
                if (in_array($key, $model->getAuditIgnoredAttributes(), true)) {
                    continue;
                }
                $filteredChanged[$key] = $newValue;
                $filteredOriginal[$key] = $original[$key] ?? null;
            }

            if ($filteredChanged === []) {
                return;
            }

            $model->recordAuditActivity('updated', $filteredOriginal, $filteredChanged);
        });

        static::deleted(function (Model $model): void {
            $model->recordAuditActivity('deleted', $model->getAttributes(), null);
        });
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function recordAuditActivity(string $action, ?array $old, ?array $new): void
    {
        $module = $this->getAuditModule();
        $label = $this->getAuditRecordLabel();
        $sellerId = $this->resolveAuditSellerId();
        $eventName = $module === 'finance' ? $this->financeAuditEventName($action, $old, $new) : $action;

        app(AuditService::class)->record([
            'module' => $module,
            'action' => $eventName,
            'description' => $module === 'finance'
                ? $this->financeAuditDescription($action, $old, $new, $label)
                : class_basename($this)." #{$this->getKey()} {$action}",
            'metadata' => $eventName !== $action ? ['change' => $action] : null,
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'record_label' => $label,
            'seller_id' => $sellerId,
            'old_values' => $old,
            'new_values' => $new,
            'changed_fields' => $new ? array_keys($new) : null,
            'status' => 'success',
        ]);
    }

    /**
     * AUD-5: money records are logged under a named event rather than a bare created/updated.
     * - Wallet transactions use their type (`escrow_release`, `payout_requested`, `payout_disbursed` …).
     * - A status change is `<record>_<new status>` (`seller_invoice_void`, `refund_paid`).
     * - A wallet balance change is `seller_wallet_balance_changed`.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    protected function financeAuditEventName(string $action, ?array $old, ?array $new): string
    {
        $base = Str::snake(class_basename($this));
        $type = $this->getAttribute('transaction_type');

        if (is_string($type) && $type !== '') {
            if ($type === 'payout') {
                return match ($action) {
                    'created' => 'payout_requested',
                    'updated' => isset($new['status']) ? 'payout_'.$new['status'] : 'payout_updated',
                    default => 'payout_'.$action,
                };
            }

            return $action === 'created' ? $type : "{$type}_{$action}";
        }

        if ($action === 'updated' && isset($new['status']) && is_string($new['status'])) {
            return "{$base}_{$new['status']}";
        }

        if ($action === 'updated' && $base === 'seller_wallet') {
            return 'seller_wallet_balance_changed';
        }

        return "{$base}_{$action}";
    }

    /**
     * AUD-5: a plain sentence for a finance entry, such as "Seller invoice INV-1: status unpaid → void".
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    protected function financeAuditDescription(string $action, ?array $old, ?array $new, ?string $label): string
    {
        $subject = Str::ucfirst(Str::lower(Str::headline(class_basename($this)))).' '.($label ?? '#'.$this->getKey());

        if ($action === 'updated' && isset($new['status'])) {
            $from = $old['status'] ?? null;

            return $from !== null
                ? "{$subject}: status {$from} → {$new['status']}"
                : "{$subject}: status set to {$new['status']}";
        }

        return match ($action) {
            'created' => "{$subject} created",
            'deleted' => "{$subject} deleted",
            default => "{$subject} changed: ".implode(', ', array_keys($new ?? [])),
        };
    }

    public function getAuditModule(): string
    {
        if (property_exists($this, 'auditModule')) {
            return $this->auditModule;
        }

        $base = class_basename($this);

        return match ($base) {
            'Product', 'ProductVariation', 'ProductPrice', 'ProductImage' => 'products',
            'Order', 'SubOrder', 'OrderItem', 'ReturnRequest' => 'orders',
            'Refund', 'WalletTransaction', 'SellerWallet', 'SellerPayment', 'SellerInvoice' => 'finance',
            'SellerProfile', 'SellerSubscription', 'SellerPlan', 'SellerSuspensionEvent' => 'sellers',
            'Coupon', 'CouponUsage', 'FlashSale', 'FlashSaleProduct', 'Banner' => 'marketing',
            'StoreSetting', 'SmsSetting', 'SmsTemplate', 'EmailTemplate', 'PaymentMethod' => 'settings',
            'EmailLog', 'SmsLog' => 'communications',
            'ProductReview', 'Wishlist', 'CustomerAddress', 'RfqRequest' => 'customer_activity',
            'Category', 'Brand', 'Unit', 'Attribute', 'AttributeValue' => 'catalog',
            default => 'catalog',
        };
    }

    public function getAuditRecordLabel(): ?string
    {
        $label = $this->recipient_email
            ?? $this->recipient_phone
            ?? $this->code
            ?? $this->sku
            ?? $this->order_number
            ?? $this->sub_order_number
            ?? $this->invoice_number
            ?? $this->title
            ?? $this->store_name
            ?? $this->subject
            ?? $this->key
            ?? $this->name
            ?? $this->getKey();

        return $label !== null ? (string) $label : null;
    }

    public function resolveAuditSellerId(): ?int
    {
        if (isset($this->seller_id)) {
            return (int) $this->seller_id;
        }
        if (isset($this->user_id) && class_basename($this) === 'SellerProfile') {
            return (int) $this->user_id;
        }
        if (method_exists($this, 'product')) {
            if ($this->relationLoaded('product')) {
                return $this->product?->seller_id !== null ? (int) $this->product->seller_id : null;
            }
            if (isset($this->product_id)) {
                $sellerId = Product::query()->whereKey($this->product_id)->value('seller_id');

                return $sellerId !== null ? (int) $sellerId : null;
            }
        }
        if (method_exists($this, 'subOrder')) {
            if ($this->relationLoaded('subOrder')) {
                return $this->subOrder?->seller_id !== null ? (int) $this->subOrder->seller_id : null;
            }
            if (isset($this->sub_order_id)) {
                $sellerId = SubOrder::query()->whereKey($this->sub_order_id)->value('seller_id');

                return $sellerId !== null ? (int) $sellerId : null;
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public function getAuditIgnoredAttributes(): array
    {
        $default = ['updated_at', 'views_count', 'sales_count'];

        return property_exists($this, 'auditIgnoredAttributes')
            ? array_values(array_unique(array_merge($default, (array) $this->auditIgnoredAttributes)))
            : $default;
    }
}
