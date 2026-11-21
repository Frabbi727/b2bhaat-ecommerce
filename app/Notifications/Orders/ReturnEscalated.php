<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Filament\Admin\Resources\ReturnRequests\ReturnRequestResource;
use App\Models\ReturnRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Roadmap 3.4 admin bell entry: a buyer asked B2Bhaat to review a return the seller rejected.
 */
class ReturnEscalated extends Notification
{
    use Queueable;

    public function __construct(public ReturnRequest $returnRequest) {}

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
                    ->label('Review it')
                    ->button()
                    ->url(ReturnRequestResource::getUrl(panel: 'admin', parameters: ['filters' => ['status' => ['value' => ReturnRequest::STATUS_ESCALATED]]])),
            ]);

        return [...$this->toArray($notifiable), ...$bell->getDatabaseMessage()];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $parcel = $this->returnRequest->subOrder?->sub_order_number ?? '#'.$this->returnRequest->sub_order_id;

        return [
            'title' => "Buyer asks B2Bhaat to review a return: {$parcel}",
            'body' => 'The seller rejected it. The buyer says: '.str($this->returnRequest->escalation_note)->limit(140).' The seller\'s payment is on hold until you decide.',
            'return_request_id' => $this->returnRequest->id,
        ];
    }
}
