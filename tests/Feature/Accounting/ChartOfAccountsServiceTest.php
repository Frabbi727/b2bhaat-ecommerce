<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Exceptions\Accounting\InactiveAccountException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingAccountGroup;
use App\Services\Accounting\AccountResolverService;
use App\Services\Accounting\ChartOfAccountsService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChartOfAccountsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_groups_and_all_standard_system_accounts(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->assertGreaterThanOrEqual(5, AccountingAccountGroup::count());
        $this->assertGreaterThanOrEqual(19, AccountingAccount::count());

        $resolver = app(AccountResolverService::class);
        $gatewayAccount = $resolver->resolveBySlug('gateway_clearing');
        $escrowAccount = $resolver->resolveBySlug('seller_escrow_payable');
        $commissionAccount = $resolver->resolveBySlug('commission_revenue');

        $this->assertSame('1030', $gatewayAccount->code);
        $this->assertSame('2010', $escrowAccount->code);
        $this->assertSame('4010', $commissionAccount->code);
        $this->assertTrue($gatewayAccount->is_system);
        $this->assertTrue($gatewayAccount->is_active);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $firstCount = AccountingAccount::count();
        $groupCount = AccountingAccountGroup::count();

        // Run second time
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->assertSame($firstCount, AccountingAccount::count());
        $this->assertSame($groupCount, AccountingAccountGroup::count());
    }

    public function test_resolver_throws_inactive_account_exception_for_deactivated_account(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $resolver = app(AccountResolverService::class);
        $account = $resolver->resolveBySlug('gateway_clearing');
        // A system account cannot be switched off through the model (ACC-20); simulate one switched off in the database
        $account->updateQuietly(['is_active' => false]);
        $resolver->clearCache();

        $this->expectException(InactiveAccountException::class);
        $resolver->resolveBySlug('gateway_clearing');
    }

    public function test_resolver_resolves_by_id_and_caches_result(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $resolver = app(AccountResolverService::class);
        $gatewayAccount = $resolver->resolveBySlug('gateway_clearing');

        $resolvedById = $resolver->resolveById($gatewayAccount->id);
        $this->assertSame($gatewayAccount->id, $resolvedById->id);
        $this->assertSame($gatewayAccount->code, $resolvedById->code);

        // Deactivating without clearing cache returns cached active model
        // A system account cannot be switched off through the model (ACC-20); simulate one switched off in the database
        $gatewayAccount->updateQuietly(['is_active' => false]);
        $cached = $resolver->resolveById($gatewayAccount->id);
        $this->assertSame($gatewayAccount->id, $cached->id);

        // After clearing cache, resolving by id throws InactiveAccountException
        $resolver->clearCache();
        $this->expectException(InactiveAccountException::class);
        $resolver->resolveById($gatewayAccount->id);
    }

    public function test_resolver_throws_model_not_found_for_missing_slug_or_id(): void
    {
        $resolver = app(AccountResolverService::class);

        $this->expectException(ModelNotFoundException::class);
        $resolver->resolveBySlug('non_existent_slug');
    }

    public function test_chart_of_accounts_service_can_create_custom_child_account(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $service = app(ChartOfAccountsService::class);
        $resolver = app(AccountResolverService::class);

        $parent = $resolver->resolveBySlug('bank_operating');

        $child = $service->createAccount([
            'group_id' => $parent->group_id,
            'parent_id' => $parent->id,
            'code' => '1021',
            'system_slug' => 'bank_city_bank_operating',
            'name' => 'City Bank Principal Account',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'description' => 'Main corporate operational account at City Bank',
        ]);

        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('1021', $child->code);
        $this->assertFalse($child->is_system);
        $this->assertTrue($child->is_active);
    }

    public function test_chart_of_accounts_service_validates_unique_code(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $service = app(ChartOfAccountsService::class);
        $resolver = app(AccountResolverService::class);

        $parent = $resolver->resolveBySlug('bank_operating');

        $this->expectException(ValidationException::class);

        $service->createAccount([
            'group_id' => $parent->group_id,
            'code' => '1020', // already exists for bank_operating
            'name' => 'Duplicate Code Account',
            'type' => AccountType::ASSET,
            'normal_balance' => NormalBalance::DEBIT,
        ]);
    }

    public function test_chart_of_accounts_service_seed_default_chart_of_accounts(): void
    {
        $service = app(ChartOfAccountsService::class);
        $service->seedDefaultChartOfAccounts();

        $this->assertGreaterThanOrEqual(19, AccountingAccount::count());
    }

    public function test_resolver_normalizes_slug_with_surrounding_whitespace(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $resolver = app(AccountResolverService::class);

        $account = $resolver->resolveBySlug('   gateway_clearing   ');
        $this->assertSame('1030', $account->code);
    }

    public function test_create_account_strictly_enforces_is_system_as_false(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $service = app(ChartOfAccountsService::class);
        $resolver = app(AccountResolverService::class);

        $parent = $resolver->resolveBySlug('bank_operating');

        $account = $service->createAccount([
            'group_id' => $parent->group_id,
            'code' => '1022',
            'name' => 'Custom Account With System Flag Attempt',
            'type' => AccountType::ASSET,
            'is_system' => true,
        ]);

        $this->assertFalse($account->is_system);
    }

    public function test_account_resolver_is_scoped_in_container(): void
    {
        $instance1 = app(AccountResolverService::class);
        $instance2 = app(AccountResolverService::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_production_seeder_includes_accounting_chart_of_accounts(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->assertGreaterThanOrEqual(5, AccountingAccountGroup::count());
        $this->assertGreaterThanOrEqual(19, AccountingAccount::count());
    }
}
