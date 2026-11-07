<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
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

        $actorRole = $actor?->role?->slug;

        return [
            'correlation_id' => $this->correlationId ?: (string) Str::uuid(),
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'device_type' => $this->deviceType,
            'browser' => $this->browser,
            'os' => $this->os,
            'request_method' => $this->requestMethod,
            'request_url' => $this->requestUrl,
            'route_name' => $routeName,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_role' => $actorRole,
            'auth_guard' => $guard,
        ];
    }
}
