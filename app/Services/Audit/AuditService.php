<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Jobs\LogAuditJob;
use App\Models\AuditLog;
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
                return AuditLog::create($merged);
            }

            LogAuditJob::dispatch($merged);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Audit logging failed: '.$e->getMessage(), ['attributes' => $attributes]);

            return null;
        }
    }
}
