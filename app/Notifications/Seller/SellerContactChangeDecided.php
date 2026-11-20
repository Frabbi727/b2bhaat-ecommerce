<?php

declare(strict_types=1);

namespace App\Notifications\Seller;

use App\Models\SellerProfile;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller whether their new business address and phone were approved.
 */
class SellerContactChangeDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SellerProfile $profile,
        public bool $approved,
    ) {}

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
            ->title($this->approved ? 'Your new business address was approved' : 'Your business address change was not approved')
            ->body($this->approved
                ? 'Your invoices, challans and shipping labels now show the new address and phone.'
                : 'Your old address and phone stay on your documents. Contact support if you need help.');

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
            'seller_profile_id' => $this->profile->id,
            'approved' => $this->approved,
        ];
    }
}
