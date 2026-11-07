<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\ImmutableJournalException;
use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccountingJournalEntry extends Model
{
    use Auditable;

    /**
     * @var string
     */
    protected $table = 'accounting_journal_entries';

    protected string $auditModule = 'accounting';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'journal_number',
        'period_id',
        'entry_date',
        'posting_date',
        'status',
        'financial_event',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'description',
        'reversal_journal_id',
        'created_by_user_id',
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'entry_date' => 'date',
        'posting_date' => 'datetime',
        'metadata' => 'json',
        'status' => JournalEntryStatus::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (AccountingJournalEntry $entry): void {
            $originalStatus = $entry->getOriginal('status');
            $isPosted = $originalStatus === JournalEntryStatus::POSTED
                || $originalStatus === JournalEntryStatus::POSTED->value;
            $isReversed = $originalStatus === JournalEntryStatus::REVERSED
                || $originalStatus === JournalEntryStatus::REVERSED->value;

            if ($isReversed) {
                throw ImmutableJournalException::cannotModify($entry->journal_number);
            }

            if ($isPosted) {
                $dirtyKeys = array_keys($entry->getDirty());
                $disallowed = array_diff($dirtyKeys, ['reversal_journal_id', 'status', 'updated_at']);

                $newStatus = $entry->status instanceof JournalEntryStatus ? $entry->status : JournalEntryStatus::tryFrom((string) $entry->status);

                if (! empty($disallowed) || $newStatus !== JournalEntryStatus::REVERSED || ! $entry->reversal_journal_id) {
                    throw ImmutableJournalException::cannotModify($entry->journal_number);
                }
            }
        });

        static::deleting(function (AccountingJournalEntry $entry): void {
            $originalStatus = $entry->getOriginal('status');
            $isImmutable = $originalStatus === JournalEntryStatus::POSTED
                || $originalStatus === JournalEntryStatus::POSTED->value
                || $originalStatus === JournalEntryStatus::REVERSED
                || $originalStatus === JournalEntryStatus::REVERSED->value;

            if ($isImmutable) {
                throw ImmutableJournalException::cannotModify($entry->journal_number);
            }
        });
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    /**
     * @return HasMany<AccountingJournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AccountingJournalLine::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<AccountingJournalEntry, $this>
     */
    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_journal_id');
    }

    /**
     * @return HasMany<AccountingJournalEntry, $this>
     */
    public function reversedEntries(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_journal_id');
    }

    /**
     * Alias for journal_number to match standard accounting nomenclature and resource schemas.
     */
    public function getEntryNumberAttribute(): ?string
    {
        return $this->journal_number;
    }

    /**
     * Computed total debit amount across all lines.
     */
    public function getTotalDebitAttribute(): float
    {
        return (float) $this->lines->sum('debit');
    }

    /**
     * Computed total credit amount across all lines.
     */
    public function getTotalCreditAttribute(): float
    {
        return (float) $this->lines->sum('credit');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }
}
