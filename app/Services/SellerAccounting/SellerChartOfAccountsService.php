<?php

declare(strict_types=1);

namespace App\Services\SellerAccounting;

use App\Enums\Accounting\AccountType;
use App\Models\SellerAccounting\SellerAccount;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seller books: each seller's chart of accounts. The system accounts below are created on first use; a seller may
 * add their own accounts (mostly expense heads) on top.
 */
class SellerChartOfAccountsService
{
    public const CASH = 'cash';

    public const BANK = 'bank';

    public const B2BHAAT_ON_HOLD = 'b2bhaat_on_hold';

    public const B2BHAAT_AVAILABLE = 'b2bhaat_available';

    public const PAYOUT_IN_TRANSIT = 'payout_in_transit';

    public const INVENTORY = 'inventory';

    public const ACCOUNTS_PAYABLE = 'accounts_payable';

    public const REFUNDS_OWED = 'refunds_owed';

    public const LOANS = 'loans';

    public const CAPITAL = 'owner_capital';

    public const DRAWINGS = 'owner_drawings';

    public const OPENING_BALANCE = 'opening_balance_equity';

    public const SALES = 'sales';

    public const DELIVERY_INCOME = 'delivery_income';

    public const SALES_RETURNS = 'sales_returns';

    public const OTHER_INCOME = 'other_income';

    public const COGS = 'cost_of_goods_sold';

    public const COMMISSION = 'b2bhaat_commission';

    public const PLATFORM_FEES = 'platform_fees';

    /**
     * Accounts that mirror the seller's B2Bhaat wallet. Only automatic entries may touch them, so they always
     * agree with the wallet.
     */
    public const WALLET_MIRROR_ACCOUNTS = [self::B2BHAAT_ON_HOLD, self::B2BHAAT_AVAILABLE, self::PAYOUT_IN_TRANSIT];

    /** Expense accounts filled only by automatic entries; not offered when recording an expense. */
    public const AUTOMATIC_EXPENSE_ACCOUNTS = [self::COGS, self::COMMISSION, self::PLATFORM_FEES];

    /** First code handed to a seller-added account of each type. */
    private const CUSTOM_CODE_START = [
        'asset' => 1500,
        'liability' => 2500,
        'equity' => 3500,
        'revenue' => 4500,
        'expense' => 5500,
    ];

    /**
     * @var array<string, array{code: string, name: string, type: AccountType}>
     */
    public const DEFAULT_ACCOUNTS = [
        self::CASH => ['code' => '1010', 'name' => 'Cash in hand', 'type' => AccountType::ASSET],
        self::BANK => ['code' => '1020', 'name' => 'Bank & mobile banking', 'type' => AccountType::ASSET],
        self::B2BHAAT_ON_HOLD => ['code' => '1110', 'name' => 'B2Bhaat – money on hold', 'type' => AccountType::ASSET],
        self::B2BHAAT_AVAILABLE => ['code' => '1120', 'name' => 'B2Bhaat – available balance', 'type' => AccountType::ASSET],
        self::PAYOUT_IN_TRANSIT => ['code' => '1130', 'name' => 'Payouts on the way', 'type' => AccountType::ASSET],
        self::INVENTORY => ['code' => '1210', 'name' => 'Stock (inventory)', 'type' => AccountType::ASSET],
        self::ACCOUNTS_PAYABLE => ['code' => '2010', 'name' => 'Supplier dues', 'type' => AccountType::LIABILITY],
        self::REFUNDS_OWED => ['code' => '2020', 'name' => 'Refunds owed to buyers', 'type' => AccountType::LIABILITY],
        self::LOANS => ['code' => '2030', 'name' => 'Loans', 'type' => AccountType::LIABILITY],
        self::CAPITAL => ['code' => '3010', 'name' => "Owner's capital", 'type' => AccountType::EQUITY],
        self::DRAWINGS => ['code' => '3020', 'name' => "Owner's withdrawals", 'type' => AccountType::EQUITY],
        self::OPENING_BALANCE => ['code' => '3030', 'name' => 'Opening balances', 'type' => AccountType::EQUITY],
        self::SALES => ['code' => '4010', 'name' => 'Sales', 'type' => AccountType::REVENUE],
        self::DELIVERY_INCOME => ['code' => '4020', 'name' => 'Delivery charges earned', 'type' => AccountType::REVENUE],
        self::SALES_RETURNS => ['code' => '4090', 'name' => 'Sales returns', 'type' => AccountType::REVENUE],
        self::OTHER_INCOME => ['code' => '4100', 'name' => 'Other income', 'type' => AccountType::REVENUE],
        self::COGS => ['code' => '5010', 'name' => 'Cost of goods sold', 'type' => AccountType::EXPENSE],
        self::COMMISSION => ['code' => '5020', 'name' => 'B2Bhaat commission', 'type' => AccountType::EXPENSE],
        self::PLATFORM_FEES => ['code' => '5030', 'name' => 'B2Bhaat subscription & listing fees', 'type' => AccountType::EXPENSE],
        'rent' => ['code' => '5110', 'name' => 'Rent', 'type' => AccountType::EXPENSE],
        'salaries' => ['code' => '5120', 'name' => 'Salaries & wages', 'type' => AccountType::EXPENSE],
        'packaging' => ['code' => '5130', 'name' => 'Packaging', 'type' => AccountType::EXPENSE],
        'transport' => ['code' => '5140', 'name' => 'Transport & delivery', 'type' => AccountType::EXPENSE],
        'utilities' => ['code' => '5150', 'name' => 'Electricity, gas & internet', 'type' => AccountType::EXPENSE],
        'marketing' => ['code' => '5160', 'name' => 'Marketing & ads', 'type' => AccountType::EXPENSE],
        'other_expense' => ['code' => '5190', 'name' => 'Other expenses', 'type' => AccountType::EXPENSE],
    ];

    /**
     * Create any missing system accounts for this seller. Safe to call any number of times.
     */
    public function ensureFor(int $sellerId): void
    {
        $existing = SellerAccount::query()->where('seller_id', $sellerId)->where('is_system', true)->count();
        if ($existing >= count(self::DEFAULT_ACCOUNTS)) {
            return;
        }

        $now = now();
        $rows = [];
        foreach (self::DEFAULT_ACCOUNTS as $slug => $account) {
            $rows[] = [
                'seller_id' => $sellerId,
                'code' => $account['code'],
                'name' => $account['name'],
                'slug' => $slug,
                'type' => $account['type']->value,
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SellerAccount::query()->insertOrIgnore($rows);
    }

    /**
     * @return array<string, int> account id by slug
     */
    public function accountIds(int $sellerId): array
    {
        $this->ensureFor($sellerId);

        return SellerAccount::query()->where('seller_id', $sellerId)->pluck('id', 'slug')->all();
    }

    /**
     * @return Collection<int, SellerAccount>
     */
    public function accounts(int $sellerId): Collection
    {
        $this->ensureFor($sellerId);

        return SellerAccount::query()->where('seller_id', $sellerId)->orderBy('code')->get();
    }

    /**
     * Accounts money can be paid from or into: cash, bank, and any asset account the seller added (a second bank,
     * a bKash wallet).
     *
     * @return array<int, string> name by id
     */
    public function moneyAccountOptions(int $sellerId): array
    {
        return $this->accounts($sellerId)
            ->filter(fn (SellerAccount $account): bool => $account->is_active
                && $account->type === AccountType::ASSET
                && (in_array($account->slug, [self::CASH, self::BANK], true) || ! $account->is_system))
            ->mapWithKeys(fn (SellerAccount $account): array => [$account->id => $account->name])
            ->all();
    }

    /**
     * Expense heads a seller can record a cost against.
     *
     * @return array<int, string> name by id
     */
    public function expenseAccountOptions(int $sellerId): array
    {
        return $this->accounts($sellerId)
            ->filter(fn (SellerAccount $account): bool => $account->is_active
                && $account->type === AccountType::EXPENSE
                && ! in_array($account->slug, self::AUTOMATIC_EXPENSE_ACCOUNTS, true))
            ->mapWithKeys(fn (SellerAccount $account): array => [$account->id => $account->name])
            ->all();
    }

    /**
     * Every account a seller may use in a manual journal: all active ones except the wallet mirrors.
     *
     * @return array<int, string> "code · name" by id
     */
    public function manualAccountOptions(int $sellerId): array
    {
        return $this->accounts($sellerId)
            ->filter(fn (SellerAccount $account): bool => $account->is_active
                && ! in_array($account->slug, self::WALLET_MIRROR_ACCOUNTS, true))
            ->mapWithKeys(fn (SellerAccount $account): array => [$account->id => "{$account->code} · {$account->name}"])
            ->all();
    }

    /**
     * Add a seller's own account with the next free code for its type.
     */
    public function addAccount(int $sellerId, string $name, AccountType $type): SellerAccount
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Give the account a name.');
        }

        $this->ensureFor($sellerId);

        return DB::transaction(function () use ($sellerId, $name, $type): SellerAccount {
            if (SellerAccount::query()->where('seller_id', $sellerId)->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists()) {
                throw new DomainException("You already have an account called \"{$name}\".");
            }

            $start = self::CUSTOM_CODE_START[$type->value];
            $highest = SellerAccount::query()
                ->where('seller_id', $sellerId)
                ->where('is_system', false)
                ->where('type', $type->value)
                ->lockForUpdate()
                ->pluck('code')
                ->map(fn (string $code): int => (int) $code)
                ->max();

            $code = max($start, ((int) $highest) + 1);
            if ($code >= $start + 500) {
                throw new DomainException('You have reached the limit of accounts of this kind.');
            }

            return SellerAccount::create([
                'seller_id' => $sellerId,
                'code' => (string) $code,
                'name' => $name,
                'slug' => 'custom_'.$code,
                'type' => $type,
                'is_system' => false,
                'is_active' => true,
            ]);
        });
    }
}
