<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Support\UserAgentParser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditRequestContextMiddleware
{
    public function __construct(private readonly AuditContext $auditContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid();
        $request->headers->set('X-Correlation-ID', $correlationId);
        $uaString = $request->userAgent();
        $parsedUa = UserAgentParser::parse($uaString);

        $guard = null;
        if (Auth::guard('web')->check()) {
            $guard = 'web';
        } elseif (Auth::guard('sanctum')->check()) {
            $guard = 'sanctum';
        }

        $this->auditContext->initialize(
            correlationId: $correlationId,
            ipAddress: $request->ip(),
            userAgent: $uaString,
            deviceType: $parsedUa['device_type'],
            browser: $parsedUa['browser'],
            os: $parsedUa['os'],
            requestMethod: $request->method(),
            requestUrl: $request->fullUrl(),
            routeName: $request->route()?->getName(),
            actor: $request->user(),
            authGuard: $guard,
        );

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
