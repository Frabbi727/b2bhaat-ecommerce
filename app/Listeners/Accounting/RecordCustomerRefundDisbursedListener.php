<?php

declare(strict_types=1);

namespace App\Listeners\Accounting;

use App\Events\Finance\RefundSettledEvent;
use App\Services\Accounting\EventPipeline\CustomerRefundDisbursementJournalHandler;

class RecordCustomerRefundDisbursedListener
{
    public function handle(RefundSettledEvent $event): void
    {
        app(CustomerRefundDisbursementJournalHandler::class)->recordRefundDisbursed($event->refund);
    }
}
