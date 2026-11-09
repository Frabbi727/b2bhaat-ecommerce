<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SellerPlans\Pages;

use App\Filament\Admin\Resources\SellerPlans\SellerPlanResource;
use App\Services\Monetization\PlanTermsChangeNotifier;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSellerPlan extends EditRecord
{
    protected static string $resource = SellerPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Sellers already on the plan keep their terms until renewal and are told so by SMS (MON-2).
     */
    protected function afterSave(): void
    {
        $notifier = app(PlanTermsChangeNotifier::class);

        if (! $notifier->termsChanged($this->record)) {
            return;
        }

        $count = $notifier->notifySubscribers($this->record);

        if ($count > 0) {
            Notification::make()
                ->title('New terms apply from each seller\'s next renewal')
                ->body($count === 1
                    ? '1 seller on this plan keeps their current terms until renewal and was sent an SMS.'
                    : "{$count} sellers on this plan keep their current terms until renewal and were sent an SMS.")
                ->info()
                ->persistent()
                ->send();
        }
    }
}
