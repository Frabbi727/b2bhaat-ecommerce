<?php

declare(strict_types=1);

namespace App\Notifications\Catalog;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller whether a new product was approved or not approved, with the full reason when it was not.
 */
class ProductReviewDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Product $product,
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
        $mail = (new MailMessage)
            ->subject($this->approved ? 'Your product was approved' : 'Your product was not approved')
            ->line('Product: '.$this->product->title);

        if ($this->approved) {
            $mail->line('Buyers can now see it in the shop.');
        } else {
            $mail->line('Reason from the reviewer:')->line((string) $this->reason);
        }

        return $mail->action(
            $this->approved ? 'View product' : 'Fix and send again',
            ProductResource::getUrl('edit', ['record' => $this->product], panel: 'seller'),
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
            ->title($this->approved ? 'Your product was approved' : 'Your product was not approved')
            ->body(e($this->product->title).($this->approved ? ' is now visible to buyers.' : ': '.e((string) $this->reason)));

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
            'product_id' => $this->product->id,
            'title' => $this->product->title,
            'approved' => $this->approved,
            'reason' => $this->reason,
        ];
    }
}
