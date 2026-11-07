<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\ImmutableJournalException;
use App\Models\Order;
use App\Models\SubOrder;
use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingJournalLine extends Model
{
    use Auditable;

    /**
     * @var string
     */
    protected $table = 'accounting_journal_lines';

    protected string $auditModule = 'accounting';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'seller_id',
        'customer_id',
        'sub_order_id',
        'order_id',
        'narration',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (AccountingJournalLine $line): void {
            $entry = AccountingJournalEntry::find($line->journal_entry_id) ?? $line->journalEntry;
            $status = $entry?->status instanceof JournalEntryStatus ? $entry->status->value : (string) $entry?->status;
            if (in_array($status, [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value], true)) {
                $journalNumber = $entry?->journal_number ?? (string) ($line->journal_entry_id ?? 'UNKNOWN');
                throw ImmutableJournalException::cannotModify($journalNumber);
            }
        });

        static::updating(function (AccountingJournalLine $line): void {
            if ($line->isDirty('journal_entry_id')) {
                $originalJournalId = $line->getOriginal('journal_entry_id');
                $originalEntry = AccountingJournalEntry::find($originalJournalId);
                $journalNumber = $originalEntry?->journal_number ?? (string) ($originalJournalId ?? 'UNKNOWN');
                throw ImmutableJournalException::cannotModify($journalNumber);
            }

            $originalJournalId = $line->getOriginal('journal_entry_id') ?? $line->journal_entry_id;
            $entry = AccountingJournalEntry::find($originalJournalId) ?? $line->journalEntry;
            $status = $entry?->status instanceof JournalEntryStatus ? $entry->status->value : (string) $entry?->status;
            if (in_array($status, [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value], true)) {
                $journalNumber = $entry?->journal_number ?? (string) ($originalJournalId ?? 'UNKNOWN');
                throw ImmutableJournalException::cannotModify($journalNumber);
            }
        });

        static::deleting(function (AccountingJournalLine $line): void {
            $originalJournalId = $line->getOriginal('journal_entry_id') ?? $line->journal_entry_id;
            $entry = AccountingJournalEntry::find($originalJournalId) ?? $line->journalEntry;
            $status = $entry?->status instanceof JournalEntryStatus ? $entry->status->value : (string) $entry?->status;
            if (in_array($status, [JournalEntryStatus::POSTED->value, JournalEntryStatus::REVERSED->value], true)) {
                $journalNumber = $entry?->journal_number ?? (string) ($originalJournalId ?? 'UNKNOWN');
                throw ImmutableJournalException::cannotModify($journalNumber);
            }
        });
    }

    /**
     * @return BelongsTo<AccountingJournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(AccountingJournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<AccountingAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class, 'sub_order_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
