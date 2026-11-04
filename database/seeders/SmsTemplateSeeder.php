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
                'content_en' => 'Dear {customer_name}, order #{order_number} confirmed! Total: BDT {order_total}. Invoice: {invoice_url}. Thanks for shopping with B2Bhaat.',
                'content_bn' => 'প্রিয় {customer_name}, আপনার #{order_number} অর্ডার সফলভাবে গৃহীত হয়েছে! মোট: {order_total} টাকা। ইনভয়েস: {invoice_url}। সাথে থাকার জন্য ধন্যবাদ।',
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
                'content_en' => 'Your order #{order_number} status is now: {order_status}. Track your parcel: {tracking_url}',
                'content_bn' => 'আপনার অর্ডার #{order_number} এর বর্তমান স্ট্যাটাস: {order_status}। ট্র্যাকিং লিংক: {tracking_url}',
                'placeholders' => ['customer_name', 'order_number', 'order_status', 'tracking_url', 'courier_name', 'tracking_number'],
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            SmsTemplate::updateOrCreate(['key' => $template['key']], $template);
        }
    }
}
