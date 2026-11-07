<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingPeriod extends Model
{
    use Auditable;

    /**
     * @var string
     */
    protected $table = 'accounting_periods';

    protected string $auditModule = 'accounting';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'fiscal_year_id',
        'period_number',
        'name',
        'start_date',
        'end_date',
        'is_closed',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'period_number' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
    ];

    /**
     * @return BelongsTo<AccountingFiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(AccountingFiscalYear::class, 'fiscal_year_id');
    }

    /**
     * @return HasMany<AccountingJournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(AccountingJournalEntry::class, 'period_id');
    }
}
