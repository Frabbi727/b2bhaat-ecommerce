<?php

declare(strict_types=1);

namespace App\Events\Sellers;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WholesaleKycDecidedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
        public bool $isApproved,
        public ?string $rejectionReason = null
    ) {}
}
