<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingAccount extends Model
{
    use Auditable;

    /**
     * @var string
     */
    protected $table = 'accounting_accounts';

    protected string $auditModule = 'accounting';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'group_id',
        'parent_id',
        'code',
        'system_slug',
        'name',
        'type',
        'normal_balance',
        'is_system',
        'is_active',
        'description',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'type' => AccountType::class,
        'normal_balance' => NormalBalance::class,
    ];

    /**
     * @return BelongsTo<AccountingAccountGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountingAccountGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<AccountingAccount, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AccountingAccount, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AccountingJournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(AccountingJournalLine::class, 'account_id');
    }
}
