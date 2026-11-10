<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingPeriod;
use App\Models\User;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * ACC-14: closing a finished month stops anything more being booked into it (JournalPostingService refuses a
 * closed period). Reopening is rarer and riskier, so only a super admin can do it. Both need a reason and are audited.
 */
class AccountingPeriodService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function close(AccountingPeriod $period, User $actor, string $reason): AccountingPeriod
    {
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($period, $actor, $reason): AccountingPeriod {
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->is_closed) {
                return $locked;
            }
            if ($locked->end_date->toDateString() >= JournalEntryData::businessDate()) {
                throw new DomainException("{$locked->name} has not ended yet, so it can't be closed.");
            }
            if ($locked->journalEntries()->where('status', JournalEntryStatus::DRAFT)->exists()) {
                throw new DomainException("{$locked->name} still has unfinished draft journals. Post or remove them first.");
            }

            $locked->update(['is_closed' => true]);
            $this->audit($locked, $actor, 'period_closed', "Closed {$locked->name}: {$reason}");

            return $locked;
        });
    }

    public function reopen(AccountingPeriod $period, User $actor, string $reason): AccountingPeriod
    {
        $reason = $this->requireReason($reason);

        if (! $actor->isSuperAdmin()) {
            throw new DomainException('Only a super admin can reopen a closed month.');
        }

        return DB::transaction(function () use ($period, $actor, $reason): AccountingPeriod {
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (! $locked->is_closed) {
                return $locked;
            }

            $locked->update(['is_closed' => false]);
            $this->audit($locked, $actor, 'period_reopened', "Reopened {$locked->name}: {$reason}");

            return $locked;
        });
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required.');
        }

        return $reason;
    }

    private function audit(AccountingPeriod $period, User $actor, string $action, string $description): void
    {
        $this->auditService->record([
            'module' => 'accounting',
            'action' => $action,
            'description' => $description,
            'auditable_type' => AccountingPeriod::class,
            'auditable_id' => $period->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'actor_role' => $actor->role?->slug,
            'status' => 'success',
            'metadata' => ['period' => $period->name],
            'sync' => true,
        ]);
    }
}
