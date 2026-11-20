<?php

declare(strict_types=1);

namespace App\Events\Promotions;

use App\Models\FlashSaleProduct;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FlashSaleSubmissionDecidedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FlashSaleProduct $submission,
        public string $status, // 'approved', 'revision_requested', 'rejected'
        public ?string $feedbackReason = null
    ) {}
}
