<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
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
        return [
            'correlation_id' => $this->correlationId ?: (string) Str::uuid(),
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'device_type' => $this->deviceType,
            'browser' => $this->browser,
            'os' => $this->os,
            'request_method' => $this->requestMethod,
            'request_url' => $this->requestUrl,
            'route_name' => $this->routeName,
            'actor_id' => $this->actor?->id,
            'actor_name' => $this->actor?->name,
            'actor_email' => $this->actor?->email,
            'actor_role' => $this->actor?->role?->slug,
            'auth_guard' => $this->authGuard,
        ];
    }
}
