<?php

declare(strict_types=1);

namespace App\Notifications\Seller;

use App\Filament\Admin\Resources\SellerProfiles\SellerProfileResource;
use App\Models\SellerProfile;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells admins that a seller asked to change the business address or phone printed on their documents.
 */
class SellerContactChangeSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SellerProfile $profile) {}

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
            ->title('Seller contact change waiting for review')
            ->body(e($this->profile->store_name).' asked to change their business address or phone.')
            ->warning()
            ->actions([
                Action::make('review')
                    ->label('Review')
                    ->url(SellerProfileResource::getUrl('edit', ['record' => $this->profile->id], panel: 'admin')),
            ]);

        return [...$this->toArray($notifiable), ...$bell->getDatabaseMessage()];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'seller_profile_id' => $this->profile->id,
            'store_name' => $this->profile->store_name,
        ];
    }
}
