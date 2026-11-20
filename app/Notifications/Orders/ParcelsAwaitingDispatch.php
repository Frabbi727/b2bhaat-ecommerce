<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Filament\Admin\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AR-16 admin bell entry: parcels that could ship have waited too long. Support can chase the seller, or cancel the
 * parcel from the order page.
 */
class ParcelsAwaitingDispatch extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $subOrderNumbers
     */
    public function __construct(public array $subOrderNumbers, public int $days) {}

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
                Action::make('open')
                    ->label('Open orders')
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
        $count = count($this->subOrderNumbers);
        $shown = implode(', ', array_slice($this->subOrderNumbers, 0, 5)).($count > 5 ? ' and '.($count - 5).' more' : '');

        return [
            'title' => $count === 1 ? "1 parcel not shipped after {$this->days} days" : "{$count} parcels not shipped after {$this->days} days",
            'body' => "Parcels {$shown}. The sellers were reminded by SMS. You can cancel a parcel from its order page.",
            'sub_order_numbers' => $this->subOrderNumbers,
        ];
    }
}
