<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Jobs\LogAuditJob;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /** @var array<string> */
    protected array $synchronousModules = [
        'auth',
        'finance',
        'kyc',
        'settings',
    ];

    public function __construct(
        private readonly AuditContext $context,
        private readonly AuditRedactor $redactor,
    ) {}

    /**
     * AUD-4: a downloaded report or statement leaves a finance audit entry naming the file and who took it.
     */
    public function recordExport(string $report, string $filename, ?int $sellerId = null): ?AuditLog
    {
        return $this->record([
            'module' => 'finance',
            'action' => 'exported',
            'description' => "Downloaded {$report} ({$filename})",
            'record_label' => $report,
            'seller_id' => $sellerId,
            'status' => 'success',
            'metadata' => ['report' => $report, 'filename' => $filename],
            'sync' => true,
        ]);
    }

    /**
     * Writes one audit entry. Never throws (AUD-3):
     * - A synchronous write runs in its own savepoint, so a failed insert can't abort the caller's PostgreSQL
     *   transaction, and the failure is reported to the error tracker rather than only logged.
     * - A queued entry is dispatched after the surrounding transaction commits, so an action that rolls back leaves
     *   no entry claiming it happened.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): ?AuditLog
    {
        try {
            $contextData = $this->context->snapshot();
            $merged = array_merge($contextData, $attributes);

            $merged['old_values'] = $this->redactor->redact($merged['old_values'] ?? null);
            $merged['new_values'] = $this->redactor->redact($merged['new_values'] ?? null);
            $merged['metadata'] = $this->redactor->redact($merged['metadata'] ?? null);
            $merged['created_at'] = $merged['created_at'] ?? now();

            $isSync = in_array($merged['module'] ?? '', $this->synchronousModules, true)
                || ($merged['sync'] ?? false);

            unset($merged['sync']);

            if ($isSync) {
                return DB::transaction(fn (): AuditLog => AuditLog::create($merged));
            }

            DB::afterCommit(fn () => LogAuditJob::dispatch($merged));

            return null;
        } catch (\Throwable $e) {
            report($e);
            Log::error('Audit logging failed: '.$e->getMessage(), [
                'module' => $attributes['module'] ?? null,
                'action' => $attributes['action'] ?? null,
            ]);

            return null;
        }
    }
}
