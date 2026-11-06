<?php

declare(strict_types=1);

namespace App\Events\Sellers;

use App\Models\SellerProfile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SellerRegisteredEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SellerProfile $profile
    ) {}
}
