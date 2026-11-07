<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:prune
                            {--days=180 : Number of days of audit logs to retain}
                            {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune historical audit logs older than the specified retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('The --days option must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $count = AuditLog::query()->where('created_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info("No audit logs older than {$days} days ({$cutoff->toDateTimeString()}) found.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Are you sure you want to permanently prune {$count} audit log(s) older than {$days} days?")) {
            $this->warn('Audit log pruning cancelled.');

            return self::SUCCESS;
        }

        $deleted = AuditLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Successfully pruned {$deleted} audit log record(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
