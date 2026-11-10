<?php

namespace Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected const COURIER_WEBHOOK_SECRET = 'test-courier-webhook-secret';

    /**
     * POST a courier webhook payload signed the way a configured courier would sign it: HMAC-SHA256 of
     * "{timestamp}.{body}" (SEC-11).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signedCourierPost(array $payload, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        config(['services.courier.webhook_secret' => self::COURIER_WEBHOOK_SECRET]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();

        return $this->rawCourierPost($body, [
            'HTTP_X_COURIER_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_COURIER_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::COURIER_WEBHOOK_SECRET),
        ]);
    }

    /**
     * @param  array<string, string>  $headers  server variables, e.g. HTTP_X_COURIER_SIGNATURE
     */
    protected function rawCourierPost(string $body, array $headers): TestResponse
    {
        return $this->call('POST', '/api/v1/webhooks/courier', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ] + $headers, $body);
    }

    /**
     * Mark products approved so buyers can see and order them (approval_status is not mass assignable).
     */
    protected function approveProducts(Product ...$products): void
    {
        foreach ($products as $product) {
            $product->forceFill(['approval_status' => 'approved', 'approved_at' => now()])->save();
        }
    }
}
