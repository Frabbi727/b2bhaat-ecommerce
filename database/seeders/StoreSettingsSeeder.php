<?php

namespace Database\Seeders;

use App\Models\StoreSetting;
use Illuminate\Database\Seeder;

/**
 * Production-safe store configuration defaults. Admins edit these in /admin afterwards.
 *
 * Only missing keys are created, so re-running the seeder (for example during a deploy) never overwrites
 * values an admin has set. Contact details (helpline, email, address, VAT/BIN) are not seeded: the admin
 * must enter the real ones, and the storefront hides them until then.
 */
class StoreSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Store Global Settings
        // Group: general
        $this->seedDefault('store_name', 'B2B Haat', 'general', 'string');

        // Group: delivery
        $this->seedDefault('delivery_fee_inside_dhaka', 70.00, 'delivery', 'float');
        $this->seedDefault('delivery_fee_sub_dhaka', 100.00, 'delivery', 'float');
        $this->seedDefault('delivery_fee_outside_dhaka', 130.00, 'delivery', 'float');
        $this->seedDefault('free_shipping_threshold', 10000.00, 'delivery', 'float');
        $this->seedDefault('delivery_sla_inside_dhaka', '24-48 Hours', 'delivery', 'string');
        $this->seedDefault('delivery_sla_sub_dhaka', '48-72 Hours', 'delivery', 'string');
        $this->seedDefault('delivery_sla_outside_dhaka', '3-5 Business Days', 'delivery', 'string');

        // Legacy delivery keys for backward compatibility
        $this->seedDefault('delivery_charge_inside_dhaka', 70.00, 'delivery', 'float');
        $this->seedDefault('delivery_charge_outside_dhaka', 130.00, 'delivery', 'float');
        $this->seedDefault('free_delivery_threshold', 10000.00, 'delivery', 'float');
        $this->seedDefault('express_delivery_charge', 150.00, 'delivery', 'float');

        // Group: inventory
        $this->seedDefault('low_stock_threshold', 10, 'inventory', 'integer');
        $this->seedDefault('stock_reservation_ttl', 15, 'inventory', 'integer');
        $this->seedDefault('mfs_verification_reminder_hours', 12, 'payments', 'integer');
        $this->seedDefault('mfs_verification_timeout_hours', 72, 'payments', 'integer');
        $this->seedDefault('allow_backorders', false, 'inventory', 'boolean');

        // Group: checkout
        $this->seedDefault('guest_checkout_enabled', true, 'checkout', 'boolean');
        $this->seedDefault('min_order_amount_retail', 200.00, 'checkout', 'float');
        $this->seedDefault('min_order_amount_wholesale', 5000.00, 'checkout', 'float');
        $this->seedDefault('cancellation_window_hours', 24, 'checkout', 'integer');
        $this->seedDefault('cod_max_order_limit', 50000.00, 'checkout', 'float');
        $this->seedDefault('return_window_days', 7, 'checkout', 'integer');

        // Group: financial
        $this->seedDefault('min_payout_amount', 500.00, 'financial', 'float');
        $this->seedDefault('escrow_holding_period_hours', 48, 'financial', 'integer');
    }

    private function seedDefault(string $key, mixed $value, string $group, string $type): void
    {
        if (StoreSetting::where('key', $key)->exists()) {
            return;
        }

        StoreSetting::setTypedValue($key, $value, $group, $type);
    }
}
