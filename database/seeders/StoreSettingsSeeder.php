<?php

namespace Database\Seeders;

use App\Models\StoreSetting;
use Illuminate\Database\Seeder;

/**
 * Production-safe store configuration defaults. Admins edit these in /admin afterwards.
 */
class StoreSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Store Global Settings
        // Group: general
        StoreSetting::setTypedValue('store_name', 'B2B Haat', 'general', 'string');
        StoreSetting::setTypedValue('helpline_phone', '+880 9612-445566', 'general', 'string');
        StoreSetting::setTypedValue('support_email', 'support@b2bhaat.com', 'general', 'string');
        StoreSetting::setTypedValue('store_address', 'Level 4, B2Bhaat Tower, 45 Motijheel C/A, Dhaka-1000', 'general', 'string');

        // Group: delivery
        StoreSetting::setTypedValue('delivery_fee_inside_dhaka', 70.00, 'delivery', 'float');
        StoreSetting::setTypedValue('delivery_fee_sub_dhaka', 100.00, 'delivery', 'float');
        StoreSetting::setTypedValue('delivery_fee_outside_dhaka', 130.00, 'delivery', 'float');
        StoreSetting::setTypedValue('free_shipping_threshold', 10000.00, 'delivery', 'float');
        StoreSetting::setTypedValue('delivery_sla_inside_dhaka', '24-48 Hours', 'delivery', 'string');
        StoreSetting::setTypedValue('delivery_sla_sub_dhaka', '48-72 Hours', 'delivery', 'string');
        StoreSetting::setTypedValue('delivery_sla_outside_dhaka', '3-5 Business Days', 'delivery', 'string');

        // Legacy delivery keys for backward compatibility
        StoreSetting::setTypedValue('delivery_charge_inside_dhaka', 70.00, 'delivery', 'float');
        StoreSetting::setTypedValue('delivery_charge_outside_dhaka', 130.00, 'delivery', 'float');
        StoreSetting::setTypedValue('free_delivery_threshold', 10000.00, 'delivery', 'float');
        StoreSetting::setTypedValue('express_delivery_charge', 150.00, 'delivery', 'float');

        // Group: inventory
        StoreSetting::setTypedValue('low_stock_threshold', 10, 'inventory', 'integer');
        StoreSetting::setTypedValue('stock_reservation_ttl', 15, 'inventory', 'integer');
        StoreSetting::setTypedValue('allow_backorders', false, 'inventory', 'boolean');

        // Group: checkout
        StoreSetting::setTypedValue('guest_checkout_enabled', true, 'checkout', 'boolean');
        StoreSetting::setTypedValue('min_order_amount_retail', 200.00, 'checkout', 'float');
        StoreSetting::setTypedValue('min_order_amount_wholesale', 5000.00, 'checkout', 'float');
        StoreSetting::setTypedValue('cancellation_window_hours', 24, 'checkout', 'integer');
        StoreSetting::setTypedValue('cod_max_order_limit', 50000.00, 'checkout', 'float');
        StoreSetting::setTypedValue('return_window_days', 7, 'checkout', 'integer');

        // Group: financial
        StoreSetting::setTypedValue('min_payout_amount', 500.00, 'financial', 'float');
        StoreSetting::setTypedValue('escrow_holding_period_hours', 48, 'financial', 'integer');
    }
}
