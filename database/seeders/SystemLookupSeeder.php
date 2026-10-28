<?php

namespace Database\Seeders;

use App\Models\FulfillmentStatus;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class SystemLookupSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles
        $roles = [
            ['name' => 'Super Administrator', 'slug' => 'super_admin'],
            ['name' => 'Verified Seller', 'slug' => 'seller'],
            ['name' => 'Wholesale Buyer (B2B)', 'slug' => 'wholesale_buyer'],
            ['name' => 'Retail Buyer (B2C)', 'slug' => 'retail_buyer'],
        ];
        foreach ($roles as $role) {
            Role::firstOrCreate(['slug' => $role['slug']], $role);
        }

        // 2. Order Statuses
        $orderStatuses = [
            ['name' => 'Pending Confirmation', 'slug' => 'pending', 'badge_color' => '#EAB308', 'sort_order' => 1],
            ['name' => 'Confirmed & Paid', 'slug' => 'confirmed', 'badge_color' => '#3B82F6', 'sort_order' => 2],
            ['name' => 'Processing', 'slug' => 'processing', 'badge_color' => '#6366F1', 'sort_order' => 3],
            ['name' => 'Completed', 'slug' => 'completed', 'badge_color' => '#10B981', 'sort_order' => 4],
            ['name' => 'Cancelled', 'slug' => 'cancelled', 'badge_color' => '#EF4444', 'sort_order' => 5],
            ['name' => 'Refunded', 'slug' => 'refunded', 'badge_color' => '#9CA3AF', 'sort_order' => 6],
        ];
        foreach ($orderStatuses as $status) {
            OrderStatus::firstOrCreate(['slug' => $status['slug']], $status);
        }

        // 3. Payment Statuses
        $paymentStatuses = [
            ['name' => 'Unpaid', 'slug' => 'unpaid', 'badge_color' => '#F59E0B', 'is_terminal' => false],
            ['name' => 'Paid', 'slug' => 'paid', 'badge_color' => '#10B981', 'is_terminal' => true],
            ['name' => 'Failed', 'slug' => 'failed', 'badge_color' => '#EF4444', 'is_terminal' => true],
            ['name' => 'Refunded', 'slug' => 'refunded', 'badge_color' => '#6B7280', 'is_terminal' => true],
        ];
        foreach ($paymentStatuses as $status) {
            PaymentStatus::firstOrCreate(['slug' => $status['slug']], $status);
        }

        // 4. Fulfillment Statuses
        $fulfillmentStatuses = [
            ['name' => 'Unfulfilled', 'slug' => 'unfulfilled', 'badge_color' => '#9CA3AF', 'sort_order' => 1],
            ['name' => 'Processing / Packaging', 'slug' => 'processing', 'badge_color' => '#3B82F6', 'sort_order' => 2],
            ['name' => 'Shipped / Handed to Courier', 'slug' => 'shipped', 'badge_color' => '#8B5CF6', 'sort_order' => 3],
            ['name' => 'In Transit', 'slug' => 'in_transit', 'badge_color' => '#06B6D4', 'sort_order' => 4],
            ['name' => 'Out for Delivery', 'slug' => 'out_for_delivery', 'badge_color' => '#F59E0B', 'sort_order' => 5],
            ['name' => 'Delivered', 'slug' => 'delivered', 'badge_color' => '#10B981', 'sort_order' => 6],
            ['name' => 'Returned', 'slug' => 'returned', 'badge_color' => '#EF4444', 'sort_order' => 7],
        ];
        foreach ($fulfillmentStatuses as $status) {
            FulfillmentStatus::firstOrCreate(['slug' => $status['slug']], $status);
        }

        // 5. Sales Channels
        $salesChannels = [
            ['name' => 'Retail Only (B2C)', 'slug' => 'retail', 'is_active' => true],
            ['name' => 'Wholesale Only (B2B)', 'slug' => 'wholesale', 'is_active' => true],
            ['name' => 'Hybrid Dual Channel (Both)', 'slug' => 'both', 'is_active' => true],
        ];
        foreach ($salesChannels as $channel) {
            SalesChannel::firstOrCreate(['slug' => $channel['slug']], $channel);
        }

        // 6. Units
        $units = [
            ['name' => 'Piece', 'symbol' => 'pc'],
            ['name' => 'Dozen', 'symbol' => 'dz'],
            ['name' => 'Carton', 'symbol' => 'ctn'],
            ['name' => 'Box', 'symbol' => 'box'],
            ['name' => 'Kilogram', 'symbol' => 'kg'],
        ];
        foreach ($units as $unit) {
            Unit::firstOrCreate(['symbol' => $unit['symbol']], $unit);
        }

        // 7. Payment Methods
        $paymentMethods = [
            ['name' => 'Cash on Delivery (COD)', 'slug' => 'cod', 'is_active' => true],
            ['name' => 'bKash Gateway', 'slug' => 'bkash', 'is_active' => true],
            ['name' => 'SSLCommerz Gateway', 'slug' => 'sslcommerz', 'is_active' => true],
            ['name' => 'Mock Sandbox Gateway', 'slug' => 'mock_gateway', 'is_active' => true],
        ];
        foreach ($paymentMethods as $pm) {
            PaymentMethod::firstOrCreate(['slug' => $pm['slug']], $pm);
        }
    }
}
