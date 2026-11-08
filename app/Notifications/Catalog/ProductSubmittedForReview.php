<?php

declare(strict_types=1);

namespace App\Notifications\Catalog;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Admin bell entry: a seller sent a product for review.
 */
class ProductSubmittedForReview extends Notification
{
    use Queueable;

    public function __construct(public Product $product) {}

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
            'product_id' => $this->product->id,
            'title' => 'New product waiting for review',
            'body' => $this->product->title,
            'seller_id' => $this->product->seller_id,
        ];
    }
}
