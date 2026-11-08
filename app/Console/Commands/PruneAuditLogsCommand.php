<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Removes routine audit entries past the retention window. Money, accounting, sign-in, billing and settings
 * history (config `audit.protected_modules`) is never removed, and every run leaves its own audit entry (AUD-1).
 */
class PruneAuditLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:prune
                            {--days= : Number of days of routine audit logs to retain (default: config audit.retention_days)}
                            {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune historical audit logs older than the specified retention window';

    public function handle(AuditService $auditService): int
    {
        $days = (int) ($this->option('days') ?? config('audit.retention_days', 180));
        if ($days <= 0) {
            $this->error('The --days option must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        /** @var list<string> $protectedModules */
        $protectedModules = config('audit.protected_modules', []);
        $prunable = fn (): Builder => AuditLog::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn (Builder $query) => $query->whereNotIn('module', $protectedModules)->orWhereNull('module'));
        $count = $prunable()->count();

        if ($count === 0) {
            $this->info("No audit logs older than {$days} days ({$cutoff->toDateTimeString()}) found.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Are you sure you want to permanently prune {$count} audit log(s) older than {$days} days?")) {
            $this->warn('Audit log pruning cancelled.');

            return self::SUCCESS;
        }

        $deleted = $prunable()->delete();

        $auditService->record([
            'module' => 'audit',
            'action' => 'pruned',
            'description' => "Removed {$deleted} routine audit log entries older than {$days} days",
            'status' => 'success',
            'metadata' => [
                'deleted' => $deleted,
                'retention_days' => $days,
                'cutoff' => $cutoff->toDateTimeString(),
                'protected_modules' => $protectedModules,
            ],
            'sync' => true,
        ]);

        $this->info("Successfully pruned {$deleted} routine audit log record(s) older than {$days} days. Protected history was kept.");

        return self::SUCCESS;
    }
}
