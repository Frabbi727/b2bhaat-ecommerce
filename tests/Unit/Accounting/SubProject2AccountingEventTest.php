<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Enums\Accounting\AccountingEvent;
use PHPUnit\Framework\TestCase;

class SubProject2AccountingEventTest extends TestCase
{
    public function test_payout_requested_event_value(): void
    {
        $this->assertSame('payout_requested', AccountingEvent::PAYOUT_REQUESTED->value);
    }

    public function test_payout_disbursed_event_value(): void
    {
        $this->assertSame('payout_disbursed', AccountingEvent::PAYOUT_DISBURSED->value);
    }

    public function test_payout_rejected_event_value(): void
    {
        $this->assertSame('payout_rejected', AccountingEvent::PAYOUT_REJECTED->value);
    }

    public function test_customer_refund_disbursed_event_value(): void
    {
        $this->assertSame('customer_refund_disbursed', AccountingEvent::CUSTOMER_REFUND_DISBURSED->value);
    }
}
