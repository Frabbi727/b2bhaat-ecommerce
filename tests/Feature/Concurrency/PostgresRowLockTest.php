<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Exceptions\InsufficientStockException;
use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Coupon;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Cart\CouponService;
use App\Services\Escrow\EscrowLedgerService;
use App\Services\Inventory\StockReservationService;
use Closure;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use DomainException;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Roadmap 1.7 / AR-01: the row locks that protect stock, coupons and payouts, tested with two real connections.
 *
 * A second connection ("the other buyer") holds a row lock; the application's own code must wait for it at its
 * locking read (seen as a lock timeout on a SELECT ... FOR UPDATE), and once the other buyer commits, must see the new
 * state and refuse. SQLite has no row locks, so
 * these run on PostgreSQL only. Data must be committed for the second connection to see it, so this class migrates
 * its own database instead of using a rolled-back transaction.
 */
#[Group('pgsql')]
class PostgresRowLockTest extends TestCase
{
    use DatabaseMigrations;

    private Connection $otherBuyer;

    protected function setUp(): void
    {
        // Skip before the app boots, so no migrations run (or roll back) on another database
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Row locks are only enforced on PostgreSQL.');
        }

        parent::setUp();

        config(['database.connections.other_buyer' => config('database.connections.pgsql')]);
        $this->otherBuyer = DB::connection('other_buyer');
    }

    protected function tearDown(): void
    {
        if (isset($this->otherBuyer)) {
            while ($this->otherBuyer->transactionLevel() > 0) {
                $this->otherBuyer->rollBack();
            }
            DB::purge('other_buyer');
        }

        parent::tearDown();
    }

    /**
     * Run $attempt while the other buyer holds a lock on $table row $id. The attempt must block on that lock.
     */
    private function assertWaitsForLock(string $table, int $id, Closure $attempt): void
    {
        $this->otherBuyer->beginTransaction();
        $this->otherBuyer->table($table)->where('id', $id)->lockForUpdate()->first();

        DB::statement("SET lock_timeout = '300ms'");
        try {
            $attempt();
            $this->fail("The application did not wait for the lock on {$table} #{$id}.");
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'expected a lock timeout, got: '.$e->getMessage());
            // It must wait at its locking read, before deciding anything; waiting only at the later write would let it
            // decide on a value the other buyer is about to change
            $this->assertStringContainsString('for update', strtolower($e->getSql()), "waited at: {$e->getSql()}");
        } finally {
            DB::statement('SET lock_timeout = 0');
        }
    }

    public function test_last_unit_of_stock_goes_to_one_buyer_only(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $variation = ProductVariation::query()->orderBy('id')->firstOrFail();
        $variation->update(['stock_quantity' => 1]);
        $reserveLastUnit = fn () => app(StockReservationService::class)->reserveStock([['variation_id' => $variation->id, 'quantity' => 1]]);

        $this->assertWaitsForLock('product_variations', $variation->id, $reserveLastUnit);

        // The other buyer takes the last unit and commits
        $this->otherBuyer->table('product_variations')->where('id', $variation->id)->update(['stock_quantity' => 0]);
        $this->otherBuyer->commit();

        $this->expectException(InsufficientStockException::class);
        try {
            $reserveLastUnit();
        } finally {
            $this->assertSame(0, (int) $variation->fresh()->stock_quantity, 'stock never goes below zero');
        }
    }

    public function test_last_use_of_a_coupon_goes_to_one_buyer_only(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $buyer = User::factory()->create();
        $coupon = Coupon::create(['code' => 'LASTONE', 'name' => 'Last one', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 50, 'min_order_amount' => 0, 'usage_limit_total' => 1, 'is_active' => true]);
        $claim = fn () => DB::transaction(fn () => app(CouponService::class)->lockForOrder($coupon->id, $buyer->id, 500.00));

        $this->assertWaitsForLock('coupons', $coupon->id, $claim);

        // The other buyer's order uses the coupon and commits
        $this->otherBuyer->table('coupons')->where('id', $coupon->id)->update(['used_count' => 1]);
        $this->otherBuyer->commit();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('maximum total usage limit');
        $claim();
    }

    public function test_a_payout_is_disbursed_once_when_two_admins_pay_it_at_the_same_time(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $year = AccountingFiscalYear::firstOrCreate(['name' => 'FY 2026-2027'], ['start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'is_closed' => false]);
        AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $year->id, 'period_number' => (int) now()->format('n')],
            ['name' => now()->format('F Y'), 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'is_closed' => false]
        );
        $seller = User::factory()->create(['role_id' => Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller'])->id, 'is_active' => true]);
        SellerWallet::create(['seller_id' => $seller->id, 'available_balance' => 5000.00, 'escrow_held_balance' => 0.00]);
        $payout = app(EscrowLedgerService::class)->requestPayout($seller, 1000.00);
        $disburse = fn (string $trxId) => app(EscrowLedgerService::class)->disbursePayout($payout, $trxId, 'bkash');

        $this->assertWaitsForLock('wallet_transactions', $payout->id, fn () => $disburse('BK-ADMIN-A'));

        // The other admin's disbursement commits first
        $this->otherBuyer->table('wallet_transactions')->where('id', $payout->id)->update(['status' => WalletTransaction::STATUS_DISBURSED]);
        $this->otherBuyer->commit();

        try {
            $disburse('BK-ADMIN-A');
            $this->fail('The payout was disbursed a second time.');
        } catch (Exception $e) {
            $this->assertStringContainsString('not awaiting disbursement', $e->getMessage());
        }
    }
}
