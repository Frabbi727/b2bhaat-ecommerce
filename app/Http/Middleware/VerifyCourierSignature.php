<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a courier webhook only when it is signed, recent and new (SEC-11):
 *  - X-Courier-Timestamp is the Unix time the courier sent it, within the tolerance window (default 5 minutes);
 *  - X-Courier-Signature is the HMAC-SHA256 of "{timestamp}.{raw body}" with the shared secret;
 *  - each signature is accepted once, so a captured message cannot be sent again. A message that was not
 *    processed successfully can be retried.
 * With no secret configured, every request is refused.
 */
class VerifyCourierSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.courier.webhook_secret');
        $signature = strtolower((string) $request->header('X-Courier-Signature', ''));
        $timestamp = (string) $request->header('X-Courier-Timestamp', '');
        $tolerance = max(1, (int) config('services.courier.webhook_tolerance_seconds', 300));

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return $this->unauthorized();
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return $this->unauthorized('Courier message is too old or its clock is wrong.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized();
        }

        // Kept a little longer than the window, so the same message is never accepted twice
        $key = 'courier-webhook-seen:'.$signature;
        if (! Cache::add($key, true, now()->addSeconds($tolerance * 2))) {
            return response()->json(['success' => false, 'message' => 'This courier message was already received.'], 409);
        }

        $response = $next($request);

        if (! $response->isSuccessful()) {
            Cache::forget($key);
        }

        return $response;
    }

    private function unauthorized(string $message = 'Invalid or missing courier signature.'): Response
    {
        return response()->json(['success' => false, 'message' => $message], 401);
    }
}
