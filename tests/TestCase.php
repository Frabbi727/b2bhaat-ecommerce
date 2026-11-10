<?php

namespace Tests;

use App\Jobs\SendSmsJob;
use App\Models\Product;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;

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
     * Press Place order and, when a shopper without an account is asked for the SMS code, enter it.
     */
    protected function placeOrderConfirmingPhone(Testable $checkout): Testable
    {
        $checkout->call('placeOrder');

        if ($checkout->get('awaitingPhoneCode')) {
            $checkout->set('phoneCode', $this->lastSentSmsCode())->call('confirmPhoneCode');
        }

        return $checkout;
    }

    /**
     * The 6-digit code in the last SMS sent: from the queued job when the queue is faked, otherwise from the mock gateway.
     */
    protected function lastSentSmsCode(): string
    {
        if (Queue::getFacadeRoot() instanceof QueueFake) {
            $message = Queue::pushed(SendSmsJob::class)->last()?->message;
        } else {
            $sent = app(SmsGatewayInterface::class)->sentMessages;
            $message = end($sent)['message'] ?? null;
        }

        preg_match('/\b(\d{6})\b/', (string) $message, $matches);

        return $matches[1] ?? '';
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
