<?php

declare(strict_types=1);

namespace App\Notifications\Catalog;

use App\Filament\Admin\Resources\ProductApprovals\ProductApprovalResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
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
     * Filament's notification bell reads the title, body, status and actions keys; the rest is kept for other readers.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $bell = FilamentNotification::make()
            ->title($this->toArray($notifiable)['title'])
            ->body(e($this->toArray($notifiable)['body']))
            ->warning()
            ->actions([
                Action::make('review')
                    ->label('Review')
                    ->button()
                    ->url(ProductApprovalResource::getUrl(panel: 'admin')),
            ]);

        return [...$this->toArray($notifiable), ...$bell->getDatabaseMessage()];
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
