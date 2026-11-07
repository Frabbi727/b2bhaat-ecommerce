<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
use App\Services\Audit\Support\UserAgentParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditContext
{
    public ?string $correlationId = null;

    public ?string $ipAddress = null;

    public ?string $userAgent = null;

    public ?string $deviceType = null;

    public ?string $browser = null;

    public ?string $os = null;

    public ?string $requestMethod = null;

    public ?string $requestUrl = null;

    public ?string $routeName = null;

    public ?User $actor = null;

    public ?string $authGuard = null;

    public function initialize(
        ?string $correlationId,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $deviceType,
        ?string $browser,
        ?string $os,
        ?string $requestMethod,
        ?string $requestUrl,
        ?string $routeName,
        ?User $actor = null,
        ?string $authGuard = null,
    ): void {
        $this->correlationId = $correlationId ?: (string) Str::uuid();
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent ? Str::limit($userAgent, 500, '') : null;
        $this->deviceType = $deviceType;
        $this->browser = $browser;
        $this->os = $os;
        $this->requestMethod = $requestMethod;
        $this->requestUrl = $requestUrl;
        $this->routeName = $routeName;
        $this->actor = $actor;
        $this->authGuard = $authGuard;
    }

    public function reset(): void
    {
        $this->correlationId = null;
        $this->ipAddress = null;
        $this->userAgent = null;
        $this->deviceType = null;
        $this->browser = null;
        $this->os = null;
        $this->requestMethod = null;
        $this->requestUrl = null;
        $this->routeName = null;
        $this->actor = null;
        $this->authGuard = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $actor = $this->actor ?? request()?->user();
        if ($this->actor === null && $actor !== null) {
            $this->actor = $actor;
        }

        $guard = $this->authGuard;
        if ($guard === null && $actor !== null) {
            $guard = Auth::guard('web')->check() ? 'web' : (Auth::guard('sanctum')->check() ? 'sanctum' : null);
            $this->authGuard = $guard;
        }

        $routeName = $this->routeName ?? request()?->route()?->getName();
        if ($this->routeName === null && $routeName !== null) {
            $this->routeName = $routeName;
        }

        $ipAddress = $this->ipAddress ?? request()?->ip();
        if ($this->ipAddress === null && $ipAddress !== null) {
            $this->ipAddress = $ipAddress;
        }

        $requestMethod = $this->requestMethod ?? request()?->method();
        if ($this->requestMethod === null && $requestMethod !== null) {
            $this->requestMethod = $requestMethod;
        }

        $requestUrl = $this->requestUrl ?? request()?->fullUrl();
        if ($this->requestUrl === null && $requestUrl !== null) {
            $this->requestUrl = $requestUrl;
        }

        $rawUa = $this->userAgent ?? request()?->userAgent();
        $userAgent = $rawUa ? Str::limit($rawUa, 500, '') : null;
        if ($this->userAgent === null && $userAgent !== null) {
            $this->userAgent = $userAgent;
        }

        $deviceType = $this->deviceType;
        $browser = $this->browser;
        $os = $this->os;

        if (($deviceType === null || $browser === null || $os === null) && $rawUa !== null) {
            $parsedUa = UserAgentParser::parse($rawUa);
            $deviceType ??= $parsedUa['device_type'];
            $browser ??= $parsedUa['browser'];
            $os ??= $parsedUa['os'];

            $this->deviceType ??= $deviceType;
            $this->browser ??= $browser;
            $this->os ??= $os;
        }

        $headerCorrelationId = request()?->header('X-Correlation-ID');
        $correlationId = $this->correlationId
            ?? ((is_string($headerCorrelationId) && Str::isUuid($headerCorrelationId)) ? $headerCorrelationId : null)
            ?? (string) Str::uuid();

        if ($this->correlationId === null) {
            $this->correlationId = $correlationId;
        }

        $actorRole = $actor?->role?->slug;

        return [
            'correlation_id' => $correlationId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_type' => $deviceType,
            'browser' => $browser,
            'os' => $os,
            'request_method' => $requestMethod,
            'request_url' => $requestUrl,
            'route_name' => $routeName,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_role' => $actorRole,
            'auth_guard' => $guard,
        ];
    }
}
