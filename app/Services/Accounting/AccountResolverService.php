<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\Accounting\InactiveAccountException;
use App\Models\Accounting\AccountingAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AccountResolverService
{
    /**
     * @var array<string, AccountingAccount>
     */
    private array $bySlug = [];

    /**
     * @var array<int, AccountingAccount>
     */
    private array $byId = [];

    /**
     * Resolve an accounting account by its unique system slug.
     *
     * @throws InactiveAccountException
     * @throws ModelNotFoundException
     */
    public function resolveBySlug(string $slug): AccountingAccount
    {
        $slug = trim($slug);

        if (isset($this->bySlug[$slug])) {
            return $this->bySlug[$slug];
        }

        /** @var AccountingAccount|null $account */
        $account = AccountingAccount::where('system_slug', $slug)->first();

        if ($account === null) {
            throw new ModelNotFoundException("The ledger account \"{$slug}\" is missing, so this can't be recorded and nothing was changed. Ask the admin to run: php artisan migrate --force && php artisan db:seed --class=AccountingChartOfAccountsSeeder --force");
        }

        if (! $account->is_active) {
            throw InactiveAccountException::forAccount($slug);
        }

        $this->cacheAccount($account);

        return $account;
    }

    /**
     * Resolve an accounting account by its primary key ID.
     *
     * @throws InactiveAccountException
     * @throws ModelNotFoundException
     */
    public function resolveById(int $id): AccountingAccount
    {
        if (isset($this->byId[$id])) {
            return $this->byId[$id];
        }

        /** @var AccountingAccount $account */
        $account = AccountingAccount::findOrFail($id);

        if (! $account->is_active) {
            throw InactiveAccountException::forAccount((string) $id);
        }

        $this->cacheAccount($account);

        return $account;
    }

    /**
     * Clear all cached resolved accounts from memory.
     */
    public function clearCache(): void
    {
        $this->bySlug = [];
        $this->byId = [];
    }

    /**
     * Store an account in the local cache.
     */
    private function cacheAccount(AccountingAccount $account): void
    {
        $this->byId[$account->id] = $account;

        if ($account->system_slug !== null && $account->system_slug !== '') {
            $this->bySlug[$account->system_slug] = $account;
        }
    }
}
