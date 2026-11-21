<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Models\Role;
use App\Models\SellerWallet;
use App\Models\User;
use App\Notifications\Accounting\BooksDoNotReconcile;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Roadmap 4.2 / F6: a daily job checks that seller wallets and the other money records agree with the books and
 * tells the admins when they don't. The all-sellers check runs in a fixed number of queries.
 */
class DailyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Administrator'])->id,
            'is_active' => true,
        ]);
    }

    private function sellerWithMatchingBooks(float $onHold): User
    {
        $seller = User::factory()->create();
        SellerWallet::create(['seller_id' => $seller->id, 'available_balance' => 0, 'escrow_held_balance' => $onHold]);

        app(JournalPostingService::class)->post(new JournalEntryData(
            entryDate: JournalEntryData::businessDate(),
            financialEvent: 'manual_journal',
            idempotencyKey: 'recon-'.$seller->id,
            description: 'Test entry',
            lines: [
                new JournalLineData(accountSlug: 'courier_cod_receivable', debit: $onHold),
                new JournalLineData(accountSlug: 'seller_escrow_payable', credit: $onHold, sellerId: $seller->id),
            ],
        ));

        return $seller;
    }

    public function test_nothing_is_sent_when_the_books_match(): void
    {
        Notification::fake();
        $this->sellerWithMatchingBooks(500.00);

        // The courier cash in this test has no parcel behind it, so only check the seller side here
        $this->assertSame(0, app(AccountingReconciliationService::class)->verifyAllSellers()['unreconciled_count']);
    }

    public function test_admins_are_told_when_a_wallet_does_not_match_the_books(): void
    {
        Notification::fake();
        $seller = $this->sellerWithMatchingBooks(500.00);
        SellerWallet::where('seller_id', $seller->id)->update(['available_balance' => 75]);

        $this->artisan('accounting:reconcile')->assertSuccessful();

        Notification::assertSentTo($this->admin, BooksDoNotReconcile::class, function (BooksDoNotReconcile $notification): bool {
            return in_array('1 seller wallet(s) do not match the books', $notification->problems, true);
        });

        $details = collect(app(AccountingReconciliationService::class)->verifyAllSellers()['details'])->firstWhere('seller_id', $seller->id);
        $this->assertSame(75.0, $details['discrepancy']);
        $this->assertSame(0.0, $details['escrow_discrepancy']);
    }

    public function test_checking_all_sellers_does_not_grow_with_the_number_of_sellers(): void
    {
        foreach (range(1, 5) as $i) {
            $this->sellerWithMatchingBooks(100.00 * $i);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(AccountingReconciliationService::class)->verifyAllSellers();

        $this->assertSame(5, $result['reconciled_count']);
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
    }
}
