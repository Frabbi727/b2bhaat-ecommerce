<?php

declare(strict_types=1);

namespace App\Notifications\Catalog;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\ProductChangeRequest;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller whether a price, channel or category change was approved or not approved, with the full reason when it was not.
 */
class ProductChangeDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProductChangeRequest $request,
        public bool $approved,
        public ?string $reason = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = $this->request->product;

        $mail = (new MailMessage)
            ->subject($this->approved ? 'Your price change was approved' : 'Your price change was not approved')
            ->line('Product: '.$product?->title);

        if ($this->approved) {
            $mail->line('The new prices are now live.');
        } else {
            $mail->line('Reason from the reviewer:')->line((string) $this->reason);
        }

        return $mail->action(
            $this->approved ? 'View product' : 'Review and send again',
            ProductResource::getUrl('edit', ['record' => $this->request->product_id], panel: 'seller'),
        );
    }

    /**
     * Filament's notification bell reads the title, body, status and actions keys; the rest is kept for other readers.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $bell = FilamentNotification::make()
            ->title($this->approved ? 'Your price change was approved' : 'Your price change was not approved')
            ->body(e((string) $this->request->product?->title).($this->approved ? ': the new prices are now live.' : ': '.e((string) $this->reason)));

        if ($this->approved) {
            $bell->success();
        } else {
            $bell->danger();
        }

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
            'title' => $this->request->product?->title,
            'approved' => $this->approved,
            'reason' => $this->reason,
        ];
    }
}
