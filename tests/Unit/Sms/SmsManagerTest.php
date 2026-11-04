<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use App\Models\SmsSetting;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\BulkSmsBdDriver;
use App\Services\Sms\Drivers\LogDriver;
use App\Services\Sms\Drivers\MockDriver;
use App\Services\Sms\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_mock_driver(): void
    {
        /** @var SmsManager $manager */
        $manager = app(SmsManager::class);
        $driver = $manager->driver('mock');

        $this->assertInstanceOf(MockDriver::class, $driver);
    }

    public function test_resolves_log_driver(): void
    {
        /** @var SmsManager $manager */
        $manager = app(SmsManager::class);
        $driver = $manager->driver('log');

        $this->assertInstanceOf(LogDriver::class, $driver);
    }

    public function test_resolves_bulksmsbd_driver_with_dynamic_settings(): void
    {
        SmsSetting::set('bulksmsbd_api_key', 'dynamic_key_123');
        SmsSetting::set('bulksmsbd_sender_id', 'dynamic_sender_456');

        /** @var SmsManager $manager */
        $manager = app(SmsManager::class);
        $driver = $manager->driver('bulksmsbd');

        $this->assertInstanceOf(BulkSmsBdDriver::class, $driver);
    }

    public function test_container_binds_sms_gateway_interface(): void
    {
        config(['sms.default' => 'mock']);
        $gateway = app(SmsGatewayInterface::class);

        $this->assertInstanceOf(MockDriver::class, $gateway);
    }
}
