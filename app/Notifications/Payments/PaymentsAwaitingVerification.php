<?php

declare(strict_types=1);

namespace App\Notifications\Payments;

use App\Filament\Admin\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * PAY-2 admin bell entry: bKash/Nagad payments have waited too long for a check.
 */
class PaymentsAwaitingVerification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $orderNumbers
     */
    public function __construct(public array $orderNumbers, public int $reminderHours, public int $timeoutHours) {}

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
    public function toDatabase(object $notifiable): array
    {
        $bell = FilamentNotification::make()
            ->title($this->toArray($notifiable)['title'])
            ->body(e($this->toArray($notifiable)['body']))
            ->warning()
            ->actions([
                Action::make('check')
                    ->label('Check payments')
                    ->button()
                    ->url(OrderResource::getUrl(panel: 'admin')),
            ]);

        return [...$this->toArray($notifiable), ...$bell->getDatabaseMessage()];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = count($this->orderNumbers);
        $shown = implode(', ', array_slice($this->orderNumbers, 0, 5)).($count > 5 ? ' and '.($count - 5).' more' : '');

        return [
            'title' => $count === 1 ? '1 bKash/Nagad payment waiting over '.$this->reminderHours.' hours' : "{$count} bKash/Nagad payments waiting over {$this->reminderHours} hours",
            'body' => "Orders {$shown}. Unchecked orders are cancelled after {$this->timeoutHours} hours.",
            'order_numbers' => $this->orderNumbers,
        ];
    }
}
