<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected const COURIER_WEBHOOK_SECRET = 'test-courier-webhook-secret';

    /**
     * POST a courier webhook payload signed the way a configured courier would sign it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signedCourierPost(array $payload, ?string $secret = null): TestResponse
    {
        config(['services.courier.webhook_secret' => self::COURIER_WEBHOOK_SECRET]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, $secret ?? self::COURIER_WEBHOOK_SECRET);

        return $this->call('POST', '/api/v1/webhooks/courier', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_COURIER_SIGNATURE' => $signature,
        ], $body);
    }
}
