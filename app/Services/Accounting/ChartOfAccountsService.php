<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Accounting\AccountingAccount;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChartOfAccountsService
{
    public function __construct(
        public readonly AccountResolverService $resolver
    ) {}

    /**
     * Seed or update the default chart of accounts.
     */
    public function seedDefaultChartOfAccounts(): void
    {
        /** @var AccountingChartOfAccountsSeeder $seeder */
        $seeder = resolve(AccountingChartOfAccountsSeeder::class);
        $seeder->run();

        $this->resolver->clearCache();
    }

    /**
     * Create a new custom or child accounting account.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createAccount(array $data): AccountingAccount
    {
        $validated = Validator::make($data, [
            'group_id' => ['required', 'integer', 'exists:accounting_account_groups,id'],
            'parent_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'code' => ['required', 'string', 'max:20', 'unique:accounting_accounts,code'],
            'system_slug' => ['nullable', 'string', 'max:60', 'unique:accounting_accounts,system_slug'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'normal_balance' => ['sometimes', Rule::enum(NormalBalance::class)],
            'is_system' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string'],
        ])->validate();

        $validated['is_system'] = (bool) ($data['is_system'] ?? false);
        $validated['is_active'] = (bool) ($data['is_active'] ?? true);

        if (! isset($validated['normal_balance'])) {
            $typeEnum = $validated['type'] instanceof AccountType
                ? $validated['type']
                : AccountType::from((string) $validated['type']);
            $validated['normal_balance'] = $typeEnum->defaultNormalBalance();
        }

        /** @var AccountingAccount $account */
        $account = AccountingAccount::create($validated);

        $this->resolver->clearCache();

        return $account;
    }
}
