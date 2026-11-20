<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LogAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * PERF-4: a failed write is retried, then lands in failed_jobs, instead of being dropped.
     */
    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        AuditLog::create($this->payload);
    }

    /**
     * The payload is already redacted (AuditService), so it is safe to log for manual recovery.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical('Audit log could not be saved after all retries: '.$e->getMessage(), ['payload' => $this->payload]);
    }
}
