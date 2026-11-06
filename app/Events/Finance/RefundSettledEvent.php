<?php

declare(strict_types=1);

namespace App\Events\Finance;

use App\Models\Refund;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundSettledEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Refund $refund
    ) {}
}
