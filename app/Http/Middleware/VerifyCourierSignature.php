<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a courier webhook only when its X-Courier-Signature header is the HMAC-SHA256 of the raw
 * body with the configured shared secret. With no secret configured, every request is refused.
 */
class VerifyCourierSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.courier.webhook_secret');
        $signature = (string) $request->header('X-Courier-Signature', '');

        if ($secret === '' || $signature === '') {
            return $this->unauthorized();
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['success' => false, 'message' => 'Invalid or missing courier signature.'], 401);
    }
}
