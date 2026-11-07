<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs\Widgets;

use App\Models\AuditLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AuditLogStatsWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    protected function getStats(): array
    {
        $totalEvents = AuditLog::count();
        $todayEvents = AuditLog::where('created_at', '>=', today()->startOfDay())->count();
        $failedLogins24h = AuditLog::where('action', 'login_failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $suspiciousEvents = AuditLog::where('is_suspicious', true)->count();

        return [
            Stat::make('Total Events', number_format($totalEvents))
                ->description('All recorded events')
                ->color('primary'),

            Stat::make("Today's Events", number_format($todayEvents))
                ->description('Recorded since midnight')
                ->color('info'),

            Stat::make('Failed Logins (24h)', number_format($failedLogins24h))
                ->description('Authentication failures')
                ->color($failedLogins24h > 0 ? 'danger' : 'success'),

            Stat::make('Suspicious Events', number_format($suspiciousEvents))
                ->description('Flagged security anomalies')
                ->color($suspiciousEvents > 0 ? 'danger' : 'gray'),
        ];
    }
}
