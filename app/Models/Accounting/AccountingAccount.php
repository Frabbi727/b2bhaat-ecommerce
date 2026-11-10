<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Traits\Auditable;
use DomainException;
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
        'account_group_id',
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
        'code' => 'string',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'type' => AccountType::class,
        'normal_balance' => NormalBalance::class,
    ];

    /** ACC-20: what defines a system account; the code that books journals looks these accounts up by them. */
    private const SYSTEM_LOCKED = ['system_slug', 'type', 'normal_balance', 'code', 'group_id', 'parent_id', 'is_system'];

    /** ACC-20: what decides where an account's postings land in the statements. */
    private const POSTED_LOCKED = ['type', 'normal_balance', 'code', 'group_id'];

    protected static function booted(): void
    {
        // ACC-20: enforced here, not only in the admin form, so no code path can change them
        static::updating(function (AccountingAccount $account): void {
            $wasSystem = (bool) $account->getOriginal('is_system');

            $locked = match (true) {
                $wasSystem => array_merge(self::SYSTEM_LOCKED, $account->isDirty('is_active') && ! $account->is_active ? ['is_active'] : []),
                $account->journalLines()->exists() => self::POSTED_LOCKED,
                default => [],
            };

            $changed = array_values(array_filter($locked, fn (string $field): bool => $account->isDirty($field)));

            if ($changed !== []) {
                $reason = $wasSystem ? 'it is a system account the platform books to' : 'it already has postings';

                throw new DomainException(sprintf(
                    'Account %s (%s) cannot change %s, because %s. Create a new account instead.',
                    $account->getOriginal('code'),
                    $account->getOriginal('name'),
                    implode(', ', $changed),
                    $reason,
                ));
            }
        });

        static::deleting(function (AccountingAccount $account): bool {
            if ($account->is_system) {
                return false;
            }

            if ($account->journalLines()->exists()) {
                return false;
            }

            if ($account->children()->exists()) {
                return false;
            }

            return true;
        });
    }

    public function setAccountGroupIdAttribute(?int $value): void
    {
        $this->attributes['group_id'] = $value;
    }

    public function getAccountGroupIdAttribute(): ?int
    {
        return isset($this->attributes['group_id']) ? (int) $this->attributes['group_id'] : null;
    }

    /**
     * @return BelongsTo<AccountingAccountGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountingAccountGroup::class, 'group_id');
    }

    /**
     * @return BelongsTo<AccountingAccountGroup, $this>
     */
    public function accountGroup(): BelongsTo
    {
        return $this->group();
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

    /**
     * @return HasMany<AccountingJournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->journalLines();
    }
}
