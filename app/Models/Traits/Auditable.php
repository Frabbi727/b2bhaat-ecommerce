<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    /** @var array<string> */
    protected array $auditIgnoredAttributes = [
        'updated_at',
        'views_count',
        'sales_count',
    ];

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

        app(AuditService::class)->record([
            'module' => $module,
            'action' => $action,
            'description' => class_basename($this)." #{$this->getKey()} {$action}",
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

    public function getAuditModule(): string
    {
        if (property_exists($this, 'auditModule')) {
            return $this->auditModule;
        }

        $base = class_basename($this);

        return match ($base) {
            'Product', 'ProductVariation', 'ProductPrice' => 'products',
            'Order', 'SubOrder' => 'orders',
            'Refund', 'WalletTransaction', 'SellerWallet' => 'finance',
            'SellerProfile', 'SellerSubscription' => 'sellers',
            'Coupon', 'FlashSale' => 'marketing',
            'StoreSetting', 'SmsSetting' => 'settings',
            default => 'catalog',
        };
    }

    public function getAuditRecordLabel(): ?string
    {
        return $this->sku ?? $this->order_number ?? $this->sub_order_number ?? (string) $this->getKey();
    }

    public function resolveAuditSellerId(): ?int
    {
        if (isset($this->seller_id)) {
            return (int) $this->seller_id;
        }
        if (isset($this->user_id) && class_basename($this) === 'SellerProfile') {
            return (int) $this->user_id;
        }
        if (method_exists($this, 'product') && $this->product?->seller_id) {
            return (int) $this->product->seller_id;
        }
        if (method_exists($this, 'subOrder') && $this->subOrder?->seller_id) {
            return (int) $this->subOrder->seller_id;
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public function getAuditIgnoredAttributes(): array
    {
        return $this->auditIgnoredAttributes;
    }
}
