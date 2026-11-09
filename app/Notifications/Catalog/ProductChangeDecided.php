<?php

declare(strict_types=1);

namespace App\Notifications\Catalog;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\ProductChangeRequest;
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
