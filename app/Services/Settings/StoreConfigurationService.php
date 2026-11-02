<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Cache;

class StoreConfigurationService
{
    /**
     * Retrieve a typed configuration value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return StoreSetting::getTypedValue($key, $default);
    }

    /**
     * Persist a typed configuration value.
     */
    public function set(
        string $key,
        mixed $value,
        string $group = 'general',
        string $type = 'string',
        bool $isPublic = true
    ): void {
        StoreSetting::setTypedValue($key, $value, $group, $type, $isPublic);
    }

    /**
     * Retrieve all settings grouped by domain.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllGrouped(): array
    {
        return Cache::remember('store_settings_all_grouped', 3600, function () {
            $settings = StoreSetting::all();
            $grouped = [];

            foreach ($settings as $setting) {
                $grouped[$setting->group][$setting->key] = StoreSetting::castValue($setting->value, $setting->type ?? 'string');
            }

            return $grouped;
        });
    }

    /**
     * Get store branding and identity details.
     *
     * @return array{name: string, helpline: string, email: string, address: string, vat_bin: string, announcement: string}
     */
    public function getStoreIdentity(): array
    {
        return [
            'name' => (string) $this->get('store_name', 'B2B Haat'),
            'helpline' => (string) $this->get('helpline_phone', '+880 9612-445566'),
            'email' => (string) $this->get('support_email', 'support@b2bhaat.com'),
            'address' => (string) $this->get('store_address', 'Level 4, B2Bhaat Tower, 45 Motijheel C/A, Dhaka-1000'),
            'vat_bin' => (string) $this->get('vat_bin_number', 'BIN-002394812-0101'),
            'announcement' => (string) $this->get('announcement_text', 'Mega Electronics Fest: Up to 35% off on Mini LED Smart TVs & Audio!'),
        ];
    }

    /**
     * Get delivery fee for a specific geographical zone.
     */
    public function getDeliveryFee(string $zone): float
    {
        return match ($zone) {
            'inside_dhaka' => (float) $this->get('delivery_fee_inside_dhaka', $this->get('delivery_charge_inside_dhaka', 70.00)),
            'sub_dhaka' => (float) $this->get('delivery_fee_sub_dhaka', 100.00),
            'outside_dhaka' => (float) $this->get('delivery_fee_outside_dhaka', $this->get('delivery_charge_outside_dhaka', 130.00)),
            default => (float) $this->get('delivery_fee_outside_dhaka', 130.00),
        };
    }

    /**
     * Get minimum cart total required for free shipping.
     */
    public function getFreeShippingThreshold(): float
    {
        return (float) $this->get('free_shipping_threshold', 10000.00);
    }

    /**
     * Get delivery SLA text for a specific zone.
     */
    public function getDeliverySla(string $zone): string
    {
        return match ($zone) {
            'inside_dhaka' => (string) $this->get('delivery_sla_inside_dhaka', '24-48 Hours'),
            'sub_dhaka' => (string) $this->get('delivery_sla_sub_dhaka', '48-72 Hours'),
            'outside_dhaka' => (string) $this->get('delivery_sla_outside_dhaka', '3-5 Business Days'),
            default => (string) $this->get('delivery_sla_outside_dhaka', '3-5 Business Days'),
        };
    }

    /**
     * Get stock threshold when low-stock alerts trigger.
     */
    public function getLowStockThreshold(): int
    {
        return (int) $this->get('low_stock_threshold', 10);
    }

    /**
     * Get stock reservation duration in minutes before returning reserved items to inventory.
     */
    public function getStockReservationTtl(): int
    {
        return (int) $this->get('stock_reservation_ttl', 15);
    }

    /**
     * Check if customers can place orders without logged-in accounts.
     */
    public function isGuestCheckoutAllowed(): bool
    {
        return (bool) $this->get('guest_checkout_enabled', true);
    }

    /**
     * Check if backorders are allowed when stock reaches zero.
     */
    public function isBackorderAllowed(): bool
    {
        return (bool) $this->get('allow_backorders', false);
    }

    /**
     * Get allowable hours from order placement during which buyer can self-cancel without penalty.
     */
    public function getOrderCancellationWindowHours(): int
    {
        return (int) $this->get('cancellation_window_hours', 24);
    }

    /**
     * Get maximum total order value allowed for Cash on Delivery.
     */
    public function getCodMaxLimit(): float
    {
        return (float) $this->get('cod_max_order_limit', 50000.00);
    }

    /**
     * Get minimum order basket value for given sales channel.
     */
    public function getMinOrderAmount(string $channel = 'retail'): float
    {
        return match ($channel) {
            'wholesale' => (float) $this->get('min_order_amount_wholesale', 5000.00),
            default => (float) $this->get('min_order_amount_retail', 200.00),
        };
    }
}
