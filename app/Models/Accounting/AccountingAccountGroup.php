<?php

declare(strict_types=1);

namespace App\Models\Accounting;

use App\Enums\Accounting\AccountType;
use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingAccountGroup extends Model
{
    use Auditable;

    /**
     * @var string
     */
    protected $table = 'accounting_account_groups';

    protected string $auditModule = 'accounting';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'code_prefix',
        'description',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => AccountType::class,
    ];

    /**
     * @return HasMany<AccountingAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(AccountingAccount::class, 'group_id');
    }
}
