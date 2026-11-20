<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SellerInvoice;
use App\Models\SellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUD-5: money records are logged under named events (payout_requested, payout_disbursed, seller_invoice_void …)
 * with a plain description, instead of a bare "created" or "updated".
 */
class FinanceAuditEventNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function entriesFor(string $type, int $id): Collection
    {
        return AuditLog::where('auditable_type', $type)->where('auditable_id', $id)->orderBy('id')->get();
    }

    public function test_an_invoice_status_change_is_a_named_event_with_a_readable_description(): void
    {
        $invoice = SellerInvoice::create([
            'seller_id' => $this->seller->id,
            'invoice_number' => 'INV-AUD5-1',
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499,
            'total_amount' => 499,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->addDays(3),
        ]);
        $invoice->update(['status' => SellerInvoice::STATUS_VOID]);

        $entries = $this->entriesFor(SellerInvoice::class, $invoice->id);

        $this->assertSame(['seller_invoice_created', 'seller_invoice_void'], $entries->pluck('action')->all());
        $this->assertSame('Seller invoice INV-AUD5-1: status unpaid → void', $entries->last()->description);
        $this->assertSame('updated', $entries->last()->metadata['change']);
    }

    public function test_payout_requests_and_disbursements_are_named(): void
    {
        $wallet = SellerWallet::firstOrCreate(['seller_id' => $this->seller->id], ['available_balance' => 5000, 'escrow_held_balance' => 0]);

        $payout = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_PAYOUT,
            'status' => WalletTransaction::STATUS_REQUESTED,
            'amount' => 1500,
            'running_balance' => 3500,
        ]);
        $payout->update(['status' => WalletTransaction::STATUS_DISBURSED]);

        $release = WalletTransaction::create([
            'seller_wallet_id' => $wallet->id,
            'transaction_type' => WalletTransaction::TYPE_ESCROW_RELEASE,
            'amount' => 800,
            'running_balance' => 4300,
        ]);

        $this->assertSame(['payout_requested', 'payout_disbursed'], $this->entriesFor(WalletTransaction::class, $payout->id)->pluck('action')->all());
        $this->assertSame('escrow_release', $this->entriesFor(WalletTransaction::class, $release->id)->sole()->action);
    }

    public function test_a_wallet_balance_change_is_named(): void
    {
        $wallet = SellerWallet::firstOrCreate(['seller_id' => $this->seller->id], ['available_balance' => 0, 'escrow_held_balance' => 0]);
        $wallet->update(['available_balance' => 250]);

        $this->assertSame('seller_wallet_balance_changed', $this->entriesFor(SellerWallet::class, $wallet->id)->last()->action);
    }

    public function test_payment_references_are_masked_in_the_finance_log(): void
    {
        $invoice = SellerInvoice::create([
            'seller_id' => $this->seller->id,
            'invoice_number' => 'INV-AUD5-2',
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499,
            'total_amount' => 499,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->addDays(3),
        ]);
        $payment = $invoice->payments()->create([
            'seller_id' => $this->seller->id,
            'amount' => 499,
            'payment_method' => 'bkash',
            'transaction_reference' => 'BKREF99887766',
            'status' => 'pending_verification',
        ]);

        $entry = $this->entriesFor($payment::class, $payment->id)->sole();
        $this->assertSame('••••7766', $entry->new_values['transaction_reference']);
    }

    public function test_other_modules_keep_plain_created_and_updated(): void
    {
        $product = Product::firstOrFail();
        $product->update(['title' => 'Renamed for AUD-5']);

        $this->assertSame('updated', $this->entriesFor(Product::class, $product->id)->last()->action);
    }
}
