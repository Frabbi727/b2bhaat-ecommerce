<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use App\Services\Sms\Drivers\BulkSmsBdDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkSmsBdDriverTest extends TestCase
{
    public function test_sends_sms_successfully_when_provider_returns_202(): void
    {
        Http::fake([
            'bulksmsbd.net/api/smsapi' => Http::response([
                'response_code' => 202,
                'message_id' => 'MSG12345',
                'success_message' => 'SMS Submitted Successfully',
            ], 200),
        ]);

        $driver = new BulkSmsBdDriver(
            apiKey: 'test_key',
            senderId: 'test_sender',
            baseUrl: 'http://bulksmsbd.net/api/smsapi',
            balanceUrl: 'http://bulksmsbd.net/api/getBalanceApi'
        );

        $response = $driver->send('01712345678', 'Test Message');

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('202', $response->responseCode());
        $this->assertSame('MSG12345', $response->messageId());
        $this->assertNull($response->errorMessage());
    }

    public function test_handles_insufficient_balance_error_1007(): void
    {
        Http::fake([
            'bulksmsbd.net/api/smsapi' => Http::response([
                'response_code' => 1007,
                'error_message' => 'Balance Insufficient',
            ], 200),
        ]);

        $driver = new BulkSmsBdDriver(
            apiKey: 'test_key',
            senderId: 'test_sender',
            baseUrl: 'http://bulksmsbd.net/api/smsapi',
            balanceUrl: 'http://bulksmsbd.net/api/getBalanceApi'
        );

        $response = $driver->send('01712345678', 'Test Message');

        $this->assertFalse($response->isSuccessful());
        $this->assertSame('1007', $response->responseCode());
        $this->assertSame('Balance Insufficient', $response->errorMessage());
    }

    public function test_checks_balance_successfully(): void
    {
        Http::fake([
            'bulksmsbd.net/api/getBalanceApi*' => Http::response([
                'response_code' => 202,
                'balance' => '150.50',
            ], 200),
        ]);

        $driver = new BulkSmsBdDriver(
            apiKey: 'test_key',
            senderId: 'test_sender',
            baseUrl: 'http://bulksmsbd.net/api/smsapi',
            balanceUrl: 'http://bulksmsbd.net/api/getBalanceApi'
        );

        $balance = $driver->checkBalance();
        $this->assertSame(150.50, $balance);
    }

    public function test_error_sent_as_a_list_is_reported_as_text(): void
    {
        Http::fake([
            'bulksmsbd.net/api/smsapi' => Http::response(['response_code' => 1003, 'error_message' => ['The api key field is required.']], 200),
        ]);

        $response = (new BulkSmsBdDriver(apiKey: '', senderId: 'test_sender'))->send('01712345678', 'Test Message');

        $this->assertFalse($response->isSuccessful());
        $this->assertSame('1003', $response->responseCode());
        $this->assertSame('The api key field is required.', $response->errorMessage());
    }

    public function test_balance_error_sent_as_a_list_is_reported_as_text(): void
    {
        Http::fake([
            'bulksmsbd.net/api/getBalanceApi*' => Http::response(['response_code' => 1003, 'error_message' => ['The api key field is required.']], 200),
        ]);

        $this->assertSame('The api key field is required.', (new BulkSmsBdDriver(apiKey: '', senderId: 'test_sender'))->checkBalance());
    }
}
