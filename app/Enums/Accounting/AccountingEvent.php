<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum AccountingEvent: string
{
    case ORDER_PREPAID_RECEIVED = 'order_prepaid_received';
    case COD_DELIVERED = 'cod_delivered';
    case COD_SELF_DELIVERED = 'cod_self_delivered';
    case ESCROW_RELEASED = 'escrow_released';
    case REFUND_PROCESSED = 'refund_processed';
    case SUBSCRIPTION_PAID = 'subscription_paid';
    case LISTING_PACK_PAID = 'listing_pack_paid';
    case MANUAL_JOURNAL = 'manual_journal';
}
