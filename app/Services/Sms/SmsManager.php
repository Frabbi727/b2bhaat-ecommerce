<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\SmsSetting;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\Drivers\BulkSmsBdDriver;
use App\Services\Sms\Drivers\LogDriver;
use App\Services\Sms\Drivers\MockDriver;
use Illuminate\Support\Manager;

class SmsManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $settingDriver = SmsSetting::get('sms_driver');
        if (! empty($settingDriver)) {
            return (string) $settingDriver;
        }

        return (string) $this->config->get('sms.default', 'bulksmsbd');
    }

    public function createBulksmsbdDriver(): SmsGatewayInterface
    {
        $config = $this->config->get('sms.drivers.bulksmsbd', []);

        $apiKey = SmsSetting::get('bulksmsbd_api_key', $config['api_key'] ?? '');
        $senderId = SmsSetting::get('bulksmsbd_sender_id', $config['sender_id'] ?? '');
        $baseUrl = SmsSetting::get('bulksmsbd_base_url', $config['base_url'] ?? 'http://bulksmsbd.net/api/smsapi');
        $balanceUrl = SmsSetting::get('bulksmsbd_balance_url', $config['balance_url'] ?? 'http://bulksmsbd.net/api/getBalanceApi');

        return new BulkSmsBdDriver(
            apiKey: (string) $apiKey,
            senderId: (string) $senderId,
            baseUrl: (string) $baseUrl,
            balanceUrl: (string) $balanceUrl
        );
    }

    public function createLogDriver(): SmsGatewayInterface
    {
        return new LogDriver;
    }

    public function createMockDriver(): SmsGatewayInterface
    {
        return new MockDriver;
    }
}
