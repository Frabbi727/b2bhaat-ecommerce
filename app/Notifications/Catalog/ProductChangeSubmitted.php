<?php

declare(strict_types=1);

namespace App\Notifications\Catalog;

use App\Models\ProductChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Admin bell entry: a seller sent a price, channel or category change for an approved product.
 */
class ProductChangeSubmitted extends Notification
{
    use Queueable;

    public function __construct(public ProductChangeRequest $request) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->request->product_id,
            'request_id' => $this->request->id,
            'title' => 'Price change waiting for review',
            'body' => (string) $this->request->product?->title,
            'seller_id' => $this->request->seller_id,
        ];
    }
}
