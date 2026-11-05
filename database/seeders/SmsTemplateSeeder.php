<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'key' => 'otp_registration',
                'name' => 'Registration OTP',
                'content_en' => 'Your B2Bhaat registration OTP is {otp_code}. Valid for {expiry_minutes} minutes.',
                'content_bn' => 'আপনার B2Bhaat রেজিস্ট্রেশন ওটিপি হল {otp_code}। মেয়াদ {expiry_minutes} মিনিট।',
                'placeholders' => ['otp_code', 'expiry_minutes'],
                'is_active' => true,
            ],
            [
                'key' => 'otp_login',
                'name' => 'Login OTP',
                'content_en' => 'Your B2Bhaat login OTP is {otp_code}. Valid for {expiry_minutes} minutes.',
                'content_bn' => 'আপনার B2Bhaat লগইন ওটিপি হল {otp_code}। মেয়াদ {expiry_minutes} মিনিট।',
                'placeholders' => ['otp_code', 'expiry_minutes'],
                'is_active' => true,
            ],
            [
                'key' => 'otp_password_reset',
                'name' => 'Password Reset OTP',
                'content_en' => 'Your B2Bhaat password reset OTP is {otp_code}. Valid for {expiry_minutes} minutes.',
                'content_bn' => 'আপনার B2Bhaat পাসওয়ার্ড রিসেট ওটিপি হল {otp_code}। মেয়াদ {expiry_minutes} মিনিট।',
                'placeholders' => ['otp_code', 'expiry_minutes'],
                'is_active' => true,
            ],
            [
                'key' => 'order_confirmation',
                'name' => 'Order Confirmation',
                'content_en' => 'Dear {customer_name}, order #{order_number} confirmed! Total: BDT {order_total}. Invoice: {invoice_url}. Tracking: {tracking_url}. Thanks for shopping with B2Bhaat.',
                'content_bn' => 'প্রিয় {customer_name}, আপনার #{order_number} অর্ডার সফলভাবে গৃহীত হয়েছে! মোট: {order_total} টাকা। ইনভয়েস: {invoice_url}। ট্র্যাকিং: {tracking_url}। সাথে থাকার জন্য ধন্যবাদ।',
                'placeholders' => ['customer_name', 'order_number', 'order_total', 'invoice_url', 'tracking_url'],
                'is_active' => true,
            ],
            [
                'key' => 'order_invoice_link',
                'name' => 'Order Invoice Voucher',
                'content_en' => 'Your B2Bhaat order #{order_number} invoice is ready: {invoice_url}',
                'content_bn' => 'আপনার B2Bhaat অর্ডার #{order_number} এর ইনভয়েস লিংক: {invoice_url}',
                'placeholders' => ['customer_name', 'order_number', 'invoice_url'],
                'is_active' => true,
            ],
            [
                'key' => 'order_tracking_update',
                'name' => 'Order Status & Tracking Update',
                'content_en' => 'Order #{order_number}: {order_status}. Track it here: {tracking_url}',
                'content_bn' => 'আপনার অর্ডার #{order_number} এর বর্তমান স্ট্যাটাস: {order_status}। ট্র্যাকিং লিংক: {tracking_url}',
                'placeholders' => ['customer_name', 'order_number', 'order_status', 'tracking_url', 'courier_name', 'tracking_number'],
                'is_active' => true,
            ],
            [
                'key' => 'seller_new_order',
                'name' => 'Seller: New Order',
                'content_en' => 'New order #{order_number} on {store_name}: {item_count} item(s), BDT {order_total}. Please prepare it: {seller_orders_url}',
                'content_bn' => '{store_name}-এ নতুন অর্ডার #{order_number}: {item_count}টি পণ্য, {order_total} টাকা। প্রস্তুত করুন: {seller_orders_url}',
                'placeholders' => ['order_number', 'item_count', 'order_total', 'seller_orders_url', 'store_name'],
                'is_active' => true,
            ],
            [
                'key' => 'wholesale_approved',
                'name' => 'Wholesale Account Approved',
                'content_en' => 'Dear {customer_name}, your wholesale account on {store_name} is approved. Sign in to see bulk prices: {shop_url}',
                'content_bn' => 'প্রিয় {customer_name}, {store_name}-এ আপনার হোলসেল অ্যাকাউন্ট অনুমোদিত হয়েছে। পাইকারি দাম দেখতে সাইন ইন করুন: {shop_url}',
                'placeholders' => ['customer_name', 'store_name', 'shop_url'],
                'is_active' => true,
            ],
            [
                'key' => 'wholesale_rejected',
                'name' => 'Wholesale Application Not Approved',
                'content_en' => 'Dear {customer_name}, your wholesale application on {store_name} was not approved: {reason}. You can apply again: {apply_url}',
                'content_bn' => 'প্রিয় {customer_name}, {store_name}-এ আপনার হোলসেল আবেদন অনুমোদিত হয়নি: {reason}। আবার আবেদন করতে পারেন: {apply_url}',
                'placeholders' => ['customer_name', 'store_name', 'reason', 'apply_url'],
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            SmsTemplate::updateOrCreate(['key' => $template['key']], $template);
        }
    }
}
