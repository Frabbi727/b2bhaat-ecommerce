<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\SellerInvoice;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Audit 1.6 (AR-09): the database itself refuses an order line with no quantity or a negative price, and a
 * status or type the code never writes. The constraints exist on PostgreSQL only (production and CI).
 */
class DatabaseCheckConstraintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The check constraints are added on PostgreSQL only.');
        }

        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    /**
     * @return array<string, array{array<string, int|float>}>
     */
    public static function badOrderLines(): array
    {
        return [
            'zero quantity' => [['quantity' => 0]],
            'negative quantity' => [['quantity' => -3]],
            'negative unit price' => [['unit_price' => -1]],
            'negative total' => [['total_price' => -10]],
        ];
    }

    /**
     * @param  array<string, int|float>  $change
     */
    #[DataProvider('badOrderLines')]
    public function test_an_order_line_with_no_quantity_or_a_negative_price_is_refused(array $change): void
    {
        $line = OrderItem::query()->firstOrFail();

        $this->assertRefusedByACheck(fn () => DB::table('order_items')->where('id', $line->id)->update($change));
    }

    public function test_a_valid_order_line_still_saves(): void
    {
        $line = OrderItem::query()->firstOrFail();

        DB::table('order_items')->where('id', $line->id)->update(['quantity' => 2, 'unit_price' => 0, 'total_price' => 0]);

        $this->assertSame(2, (int) $line->fresh()->quantity);
    }

    private function assertRefusedByACheck(\Closure $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode(), $exception->getMessage());

            return;
        }

        $this->fail('The write was accepted.');
    }

    public function test_an_unknown_wallet_status_or_type_is_refused(): void
    {
        $wallet = SellerWallet::firstOrCreate(
            ['seller_id' => User::where('email', 'seller@nailsfactory.com')->value('id')],
            ['available_balance' => 0, 'escrow_held_balance' => 0],
        );
        $id = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 100,
            'running_balance' => 0,
        ])->id;

        $this->assertRefusedByACheck(fn () => DB::table('wallet_transactions')->where('id', $id)->update(['status' => 'completed']));
        $this->assertRefusedByACheck(fn () => DB::table('wallet_transactions')->where('id', $id)->update(['transaction_type' => 'bonus']));
    }

    public function test_an_unknown_invoice_status_or_type_is_refused(): void
    {
        $invoice = SellerInvoice::create([
            'invoice_number' => 'INV-CHECK-X',
            'seller_id' => User::where('email', 'seller@nailsfactory.com')->value('id'),
            'invoice_type' => SellerInvoice::TYPE_MANUAL_FEE,
            'subtotal' => 100,
            'total_amount' => 100,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->toDateString(),
        ]);

        $this->assertRefusedByACheck(fn () => DB::table('seller_invoices')->where('id', $invoice->id)->update(['status' => 'overdue']));
        $this->assertRefusedByACheck(fn () => DB::table('seller_invoices')->where('id', $invoice->id)->update(['invoice_type' => 'tip']));
    }

    public function test_every_guarded_column_has_a_validated_constraint(): void
    {
        $guarded = [
            'order_items' => ['quantity', 'unit_price', 'total_price'],
            'refunds' => ['status'],
            'return_requests' => ['status', 'requested_action'],
            'wallet_transactions' => ['status', 'transaction_type'],
            'seller_invoices' => ['status', 'invoice_type'],
        ];

        foreach ($guarded as $table => $columns) {
            $definitions = collect(DB::select(
                'SELECT pg_get_constraintdef(oid) AS def, convalidated FROM pg_constraint WHERE conrelid = ?::regclass AND contype = ?',
                [$table, 'c'],
            ));

            foreach ($columns as $column) {
                $match = $definitions->first(fn (object $c): bool => str_contains($c->def, $column));
                $this->assertNotNull($match, "No check constraint on {$table}.{$column}");
                $this->assertTrue((bool) $match->convalidated, "{$table}.{$column} is not validated");
            }
        }
    }

    public function test_every_invoice_status_and_type_the_code_uses_is_allowed(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        foreach ([SellerInvoice::STATUS_UNPAID, SellerInvoice::STATUS_PAID, SellerInvoice::STATUS_PENDING_VERIFICATION, SellerInvoice::STATUS_VOID, SellerInvoice::STATUS_REFUNDED] as $i => $status) {
            SellerInvoice::create([
                'invoice_number' => "INV-CHECK-{$i}",
                'seller_id' => $seller->id,
                'invoice_type' => [SellerInvoice::TYPE_SUBSCRIPTION_FEE, SellerInvoice::TYPE_LISTING_PACK, SellerInvoice::TYPE_COD_COMMISSION_DEFICIT, SellerInvoice::TYPE_MANUAL_FEE][$i % 4],
                'subtotal' => 100,
                'total_amount' => 100,
                'status' => $status,
                'due_date' => now()->toDateString(),
            ]);
        }

        $this->assertSame(5, SellerInvoice::where('invoice_number', 'like', 'INV-CHECK-%')->count());
    }
}
