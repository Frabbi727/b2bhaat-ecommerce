<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditRedactor;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUD-7: secret link tokens and payment TrxIDs are not stored in full in the audit log. TrxIDs keep their last
 * four characters so finance can still match an entry to a bKash or Nagad statement.
 */
class AuditRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_trx_ids_are_masked_down_to_their_last_four_characters(): void
    {
        $redacted = app(AuditRedactor::class)->redact([
            'mfs_transaction_id' => '9AB7XK2Q51',
            'trx_id' => 'BKX55512345',
            'metadata' => ['transaction_id' => 'NG7788990011'],
            'amount' => 500,
        ]);

        $this->assertSame('••••2Q51', $redacted['mfs_transaction_id']);
        $this->assertSame('••••2345', $redacted['trx_id']);
        $this->assertSame('••••0011', $redacted['metadata']['transaction_id']);
        $this->assertSame(500, $redacted['amount']);
    }

    public function test_link_tokens_are_removed_from_the_stored_url_and_values(): void
    {
        $context = app(AuditContext::class);
        $context->requestUrl = 'https://b2bhaat.com/track/Zt9xQ2lmN0pQ8aB1cD3eF5gH7iJ9kL1m?ref=sms';
        $context->routeName = 'order.track.token';

        $log = app(AuditService::class)->record([
            'module' => 'orders',
            'action' => 'tracking_viewed',
            'description' => 'Tracking page opened',
            'new_values' => ['tracking_token' => 'Zt9xQ2lmN0pQ8aB1cD3eF5gH7iJ9kL1m'],
            'status' => 'success',
            'sync' => true,
        ]);

        $this->assertSame('https://b2bhaat.com/track/[REDACTED]?ref=sms', $log->request_url);
        $this->assertSame('[REDACTED]', $log->new_values['tracking_token']);
    }

    public function test_invoice_links_and_token_query_strings_are_redacted(): void
    {
        $redactor = app(AuditRedactor::class);

        $this->assertSame('https://b2bhaat.com/invoice/[REDACTED]', $redactor->redactUrl('https://b2bhaat.com/invoice/abcDEF123456'));
        $this->assertSame('https://b2bhaat.com/track/view?token=[REDACTED]&x=1', $redactor->redactUrl('https://b2bhaat.com/track/view?token=abc123&x=1'));
        $this->assertSame('https://b2bhaat.com/products/blue-shirt', $redactor->redactUrl('https://b2bhaat.com/products/blue-shirt'));
    }

    public function test_trx_ids_in_descriptions_are_masked(): void
    {
        $log = app(AuditService::class)->record([
            'module' => 'finance',
            'action' => 'payout_disbursed',
            'description' => 'Payout #12 paid via bKash (TrxID: 9AB7XK2Q51)',
            'status' => 'success',
            'sync' => true,
        ]);

        $this->assertSame('Payout #12 paid via bKash (TrxID: ••••2Q51)', AuditLog::find($log->id)->description);
    }
}
