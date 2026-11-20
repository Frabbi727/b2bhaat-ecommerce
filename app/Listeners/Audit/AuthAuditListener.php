<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Carbon;

class AuthAuditListener
{
    public function __construct(private readonly AuditService $auditService) {}

    public function handleLogin(Login $event): void
    {
        /** @var User|null $user */
        $user = $event->user;
        if (! $user) {
            return;
        }

        $this->auditService->record([
            'module' => 'auth',
            'action' => 'login',
            'description' => "User {$user->email} logged in successfully",
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'actor_role' => $user->role?->slug,
            'seller_id' => $user->isSeller() ? $user->id : null,
            'auth_guard' => $event->guard,
            'status' => 'success',
            'sync' => true,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        /** @var User|null $user */
        $user = $event->user;
        $credentials = $event->credentials;
        $identifier = ! empty($credentials['email']) ? $credentials['email'] : (! empty($credentials['phone']) ? $credentials['phone'] : 'unknown');

        $ip = request()?->ip();

        $recentFails = AuditLog::where('module', 'auth')
            ->where('action', 'login_failed')
            ->where('created_at', '>=', Carbon::now()->subMinutes(15))
            ->where(function ($q) use ($identifier, $ip): void {
                $q->where('actor_email', $identifier);
                if (! empty($ip)) {
                    $q->orWhere('ip_address', $ip);
                }
            })
            ->count();

        $isSuspicious = $recentFails >= 4; // Including this 5th attempt

        $this->auditService->record([
            'module' => 'auth',
            'action' => 'login_failed',
            'description' => "Failed login attempt for identifier: {$identifier}",
            'actor_type' => 'user',
            'actor_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email ?: (is_string($identifier) ? $identifier : null),
            'actor_role' => $user?->role?->slug,
            'seller_id' => $user?->isSeller() ? $user->id : null,
            'auth_guard' => $event->guard,
            'status' => 'failed',
            'failure_reason' => 'Invalid credentials or inactive account',
            'is_suspicious' => $isSuspicious,
            'risk_notes' => $isSuspicious ? 'Multiple failed login attempts detected in a short time frame' : null,
            'sync' => true,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        /** @var User|null $user */
        $user = $event->user;
        if (! $user) {
            return;
        }

        $this->auditService->record([
            'module' => 'auth',
            'action' => 'logout',
            'description' => "User {$user->email} logged out",
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'actor_role' => $user->role?->slug,
            'seller_id' => $user->isSeller() ? $user->id : null,
            'auth_guard' => $event->guard,
            'status' => 'success',
            'sync' => true,
        ]);
    }
}
