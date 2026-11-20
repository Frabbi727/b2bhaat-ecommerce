<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs\Widgets;

use App\Models\AuditLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class AuditLogStatsWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    protected function getStats(): array
    {
        $stats = app()->environment('testing')
            ? $this->calculateStats()
            : Cache::remember('audit_log_stats_overview', 60, fn (): array => $this->calculateStats());

        return [
            Stat::make('Total Events', number_format($stats['total']))
                ->description('All recorded events')
                ->color('primary'),

            Stat::make("Today's Events", number_format($stats['today']))
                ->description('Recorded since midnight')
                ->color('info'),

            Stat::make('Failed Logins (24h)', number_format($stats['failed_logins']))
                ->description('Authentication failures')
                ->color($stats['failed_logins'] > 0 ? 'danger' : 'success'),

            Stat::make('Suspicious Events', number_format($stats['suspicious']))
                ->description('Flagged security anomalies')
                ->color($stats['suspicious'] > 0 ? 'danger' : 'gray'),
        ];
    }

    /**
     * @return array{total: int, today: int, failed_logins: int, suspicious: int}
     */
    protected function calculateStats(): array
    {
        return [
            'total' => AuditLog::count(),
            'today' => AuditLog::where('created_at', '>=', today()->startOfDay())->count(),
            'failed_logins' => AuditLog::where('action', 'login_failed')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'suspicious' => AuditLog::where('is_suspicious', true)->count(),
        ];
    }
}
