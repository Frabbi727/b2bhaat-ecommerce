<?php

declare(strict_types=1);

namespace App\Events\Finance;

use App\Models\WalletTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayoutStatusUpdatedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WalletTransaction $payout,
        public string $status,
        public ?string $trxIdOrReason = null
    ) {}
}
