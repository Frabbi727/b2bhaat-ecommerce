<?php

declare(strict_types=1);

namespace App\Events\Orders;

use App\Models\SubOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConsignmentStatusUpdatedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SubOrder $subOrder,
        public string $statusSlug
    ) {}
}
