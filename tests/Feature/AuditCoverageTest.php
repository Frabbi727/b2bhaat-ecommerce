<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Pages\TrialBalancePage;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\Role;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerProfile;
use App\Models\SellerSuspensionEvent;
use App\Models\SmsSetting;
use App\Models\User;
use App\Services\Monetization\MonetizationSuspensionService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AUD-4: account, billing, suspension, settings and export changes leave audit entries; secrets never appear in them.
 */
class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    private function entriesFor(string $type, int $id): Collection
    {
        return AuditLog::where('auditable_type', $type)->where('auditable_id', $id)->orderBy('id')->get();
    }

    public function test_a_role_change_is_recorded_in_the_auth_log(): void
    {
        $user = User::where('email', 'customer@retail.com')->firstOrFail();
        $oldRole = $user->role_id;
        $newRole = Role::where('slug', 'wholesale_buyer')->value('id');

        $user->update(['role_id' => $newRole]);

        $entry = $this->entriesFor(User::class, $user->id)->last();
        $this->assertSame('auth', $entry->module);
        $this->assertSame(['role_id'], $entry->changed_fields);
        $this->assertSame($oldRole, $entry->old_values['role_id']);
        $this->assertSame($newRole, $entry->new_values['role_id']);
    }

    public function test_a_password_change_is_recorded_without_the_password(): void
    {
        $user = User::where('email', 'customer@retail.com')->firstOrFail();

        $user->update(['password' => Hash::make('a-new-secret-123')]);

        $entry = $this->entriesFor(User::class, $user->id)->last();
        $this->assertSame(['password'], $entry->changed_fields);
        $this->assertSame('[REDACTED]', $entry->new_values['password']);
        $this->assertStringNotContainsString($user->fresh()->password, json_encode($entry->toArray()));
    }

    public function test_signing_in_and_out_does_not_flood_the_log(): void
    {
        $user = User::where('email', 'customer@retail.com')->firstOrFail();
        $before = $this->entriesFor(User::class, $user->id)->count();

        $user->forceFill(['remember_token' => 'abc123'])->save();

        $this->assertSame($before, $this->entriesFor(User::class, $user->id)->count());
    }

    public function test_seller_bills_and_payments_are_recorded_in_the_finance_log(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();

        $invoice = SellerInvoice::create([
            'seller_id' => $seller->id,
            'invoice_number' => 'INV-AUD4-1',
            'invoice_type' => 'subscription_fee',
            'subtotal' => 499,
            'total_amount' => 499,
            'status' => SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->addDays(3),
        ]);
        $invoice->update(['status' => SellerInvoice::STATUS_VOID]);

        $payment = SellerPayment::create([
            'seller_id' => $seller->id,
            'invoice_id' => $invoice->id,
            'amount' => 499,
            'payment_method' => 'bkash',
            'transaction_reference' => 'AUD4REF',
            'status' => 'successful',
        ]);

        $invoiceEntries = $this->entriesFor(SellerInvoice::class, $invoice->id);
        // AUD-5: finance records use named events
        $this->assertSame(['seller_invoice_created', 'seller_invoice_void'], $invoiceEntries->pluck('action')->all());
        $this->assertSame('finance', $invoiceEntries->first()->module);
        $this->assertSame($seller->id, $invoiceEntries->first()->seller_id);
        $this->assertSame(['status'], $invoiceEntries->last()->changed_fields);

        $this->assertSame('seller_payment_created', $this->entriesFor(SellerPayment::class, $payment->id)->sole()->action);
    }

    public function test_a_suspension_records_the_store_status_change_and_the_event(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $profile = SellerProfile::where('user_id', $seller->id)->firstOrFail();

        $event = app(MonetizationSuspensionService::class)->suspendSeller($seller, 'Unpaid bill');

        $profileEntry = $this->entriesFor(SellerProfile::class, $profile->id)
            ->last(fn (AuditLog $entry): bool => in_array('monetization_status', $entry->changed_fields ?? [], true));
        $this->assertNotNull($profileEntry);
        $this->assertSame('suspended', $profileEntry->new_values['monetization_status']);
        $this->assertSame('created', $this->entriesFor(SellerSuspensionEvent::class, $event->id)->sole()->action);
    }

    public function test_a_secret_sms_setting_is_recorded_masked(): void
    {
        SmsSetting::set('bulksmsbd_api_key', 'live-key-should-not-leak');
        SmsSetting::set('bulksmsbd_api_key', 'second-key-should-not-leak');
        SmsSetting::set('sms_driver', 'log');

        $setting = SmsSetting::where('key', 'bulksmsbd_api_key')->firstOrFail();
        $entries = $this->entriesFor(SmsSetting::class, $setting->id);

        $this->assertCount(2, $entries);
        $this->assertStringNotContainsString('should-not-leak', json_encode($entries->toArray()));
        $this->assertSame('[REDACTED]', $entries->last()->new_values['value']);

        $driver = SmsSetting::where('key', 'sms_driver')->firstOrFail();
        $this->assertSame('log', $this->entriesFor(SmsSetting::class, $driver->id)->last()->new_values['value']);
    }

    public function test_switching_a_payment_method_off_is_recorded(): void
    {
        $method = PaymentMethod::where('slug', 'cod')->firstOrFail();

        $method->update(['is_active' => false]);

        $entry = $this->entriesFor(PaymentMethod::class, $method->id)->last();
        $this->assertSame('updated', $entry->action);
        $this->assertSame('settings', $entry->module);
        $this->assertSame(['is_active'], $entry->changed_fields);
    }

    public function test_downloading_a_report_is_recorded(): void
    {
        $this->seed(AccountingChartOfAccountsSeeder::class);
        $admin = User::where('email', 'admin@b2bhaat.com')->firstOrFail();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(TrialBalancePage::class)->call('exportCsv');

        $entry = AuditLog::where('action', 'exported')->sole();
        $this->assertSame('finance', $entry->module);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('trial balance', $entry->metadata['report']);
        $this->assertStringStartsWith('trial-balance-', $entry->metadata['filename']);
    }

    public function test_accepting_a_quotation_is_recorded_once(): void
    {
        $buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $rfq = RfqRequest::create([
            'product_id' => $product->id, 'seller_id' => $product->seller_id, 'buyer_id' => $buyer->id,
            'buyer_name' => 'B', 'buyer_email' => $buyer->email, 'buyer_phone' => '01711000000',
            'target_quantity' => 500, 'destination_district' => 'Dhaka', 'status' => 'quoted', 'seller_quoted_price' => 70,
        ]);

        $this->actingAs($buyer)->post(route('rfq.quotation.accept', $rfq->id))->assertRedirect();
        $this->actingAs($buyer)->post(route('rfq.quotation.accept', $rfq->id))->assertRedirect();

        $accepted = $this->entriesFor(RfqRequest::class, $rfq->id)
            ->filter(fn (AuditLog $entry): bool => ($entry->new_values['status'] ?? null) === 'accepted');
        $this->assertCount(1, $accepted);
        $this->assertSame($buyer->id, $accepted->first()->actor_id);
    }
}
