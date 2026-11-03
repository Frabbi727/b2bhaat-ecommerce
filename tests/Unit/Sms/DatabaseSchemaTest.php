<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Otp;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

$worktreePath = dirname(__DIR__, 3);
$_ENV['APP_BASE_PATH'] = $worktreePath;
$_SERVER['APP_BASE_PATH'] = $worktreePath;

spl_autoload_register(function (string $class) use ($worktreePath): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $worktreePath.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}, prepend: true);

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_and_otp_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('otps'));
        $this->assertTrue(Schema::hasColumns('otps', [
            'id', 'phone', 'code_hash', 'purpose', 'attempts', 'max_attempts', 'expires_at', 'verified_at', 'ip_address',
        ]));

        $this->assertTrue(Schema::hasTable('sms_logs'));
        $this->assertTrue(Schema::hasColumns('sms_logs', [
            'id', 'recipient_phone', 'message', 'purpose', 'driver', 'status', 'provider_message_id', 'provider_response_code', 'response_payload', 'error_message',
        ]));

        $this->assertTrue(Schema::hasTable('sms_templates'));
        $this->assertTrue(Schema::hasColumns('sms_templates', [
            'id', 'key', 'name', 'content_en', 'content_bn', 'placeholders', 'is_active',
        ]));

        $this->assertTrue(Schema::hasTable('sms_settings'));
        $this->assertTrue(Schema::hasColumns('sms_settings', ['id', 'key', 'value']));

        $this->assertTrue(Schema::hasColumns('orders', ['invoice_token', 'tracking_token', 'sms_confirmation_sent_at']));
        $this->assertTrue(Schema::hasColumn('users', 'phone_verified_at'));
    }

    public function test_order_automatically_generates_tokens_on_creation(): void
    {
        $this->seed(SystemLookupSeeder::class);

        $user = User::factory()->create();

        $order = Order::create([
            'order_number' => 'ORD-TEST-1234',
            'customer_id' => $user->id,
            'order_status_id' => OrderStatus::where('slug', 'pending')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->value('id'),
            'payment_method_id' => PaymentMethod::first()->id,
            'shipping_address' => ['address' => 'Dhaka, Bangladesh'],
            'total_amount' => 100,
        ]);

        $this->assertNotNull($order->invoice_token);
        $this->assertSame(40, strlen($order->invoice_token));
        $this->assertNotNull($order->tracking_token);
        $this->assertSame(40, strlen($order->tracking_token));
    }

    public function test_order_preserves_existing_tokens_if_provided(): void
    {
        $this->seed(SystemLookupSeeder::class);

        $user = User::factory()->create();

        $order = Order::create([
            'order_number' => 'ORD-TEST-5678',
            'customer_id' => $user->id,
            'order_status_id' => OrderStatus::where('slug', 'pending')->value('id'),
            'payment_status_id' => PaymentStatus::where('slug', 'unpaid')->value('id'),
            'payment_method_id' => PaymentMethod::first()->id,
            'shipping_address' => ['address' => 'Dhaka, Bangladesh'],
            'total_amount' => 100,
            'invoice_token' => 'custom-invoice-token',
            'tracking_token' => 'custom-tracking-token',
        ]);

        $this->assertSame('custom-invoice-token', $order->invoice_token);
        $this->assertSame('custom-tracking-token', $order->tracking_token);
    }

    public function test_user_casts_phone_verified_at_to_datetime(): void
    {
        $user = new User;
        $this->assertContains('phone_verified_at', $user->getFillable());
        $this->assertTrue($user->hasCast('phone_verified_at', 'datetime'));
    }

    public function test_otp_model_creation_and_casts(): void
    {
        $otp = Otp::create([
            'phone' => '8801712345678',
            'code_hash' => hash('sha256', '123456'),
            'purpose' => 'registration',
            'attempts' => 1,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->assertDatabaseHas('otps', ['phone' => '8801712345678']);
        $this->assertIsInt($otp->attempts);
        $this->assertInstanceOf(Carbon::class, $otp->expires_at);
    }

    public function test_sms_log_model_creation_and_casts(): void
    {
        $log = SmsLog::create([
            'recipient_phone' => '8801712345678',
            'message' => 'Your OTP is 123456',
            'purpose' => 'otp',
            'driver' => 'bulksmsbd',
            'status' => 'sent',
            'provider_message_id' => 'MSG123',
            'provider_response_code' => '1000',
            'response_payload' => ['success' => true],
            'retries_count' => 0,
            'sent_at' => now(),
        ]);

        $this->assertDatabaseHas('sms_logs', ['recipient_phone' => '8801712345678']);
        $this->assertIsArray($log->response_payload);
        $this->assertInstanceOf(Carbon::class, $log->sent_at);
    }

    public function test_sms_template_model_creation_and_casts(): void
    {
        $template = SmsTemplate::create([
            'key' => 'order_placed',
            'name' => 'Order Placed',
            'content_en' => 'Thank you for your order {order_number}!',
            'placeholders' => ['order_number', 'amount'],
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('sms_templates', ['key' => 'order_placed']);
        $this->assertIsArray($template->placeholders);
        $this->assertTrue($template->is_active);
    }

    public function test_sms_setting_get_and_set(): void
    {
        $this->assertNull(SmsSetting::get('non_existent_key'));
        $this->assertSame('default', SmsSetting::get('non_existent_key', 'default'));

        SmsSetting::set('sms_gateway_driver', 'bulksmsbd');
        $this->assertSame('bulksmsbd', SmsSetting::get('sms_gateway_driver'));

        SmsSetting::set('sms_gateway_driver', 'mim_sms');
        $this->assertSame('mim_sms', SmsSetting::get('sms_gateway_driver'));
    }
}
