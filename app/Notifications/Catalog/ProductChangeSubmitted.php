<?php

declare(strict_types=1);

namespace App\Notifications\Catalog;

use App\Filament\Admin\Resources\ChangeRequestApprovals\ChangeRequestApprovalResource;
use App\Models\ProductChangeRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
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
                    ->url(ChangeRequestApprovalResource::getUrl(panel: 'admin')),
            ]);

        return [...$this->toArray($notifiable), ...$bell->getDatabaseMessage()];
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
