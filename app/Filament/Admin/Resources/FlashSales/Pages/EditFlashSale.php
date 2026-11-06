<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Pages;

use App\Enums\FlashSaleStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Filament\Admin\Resources\FlashSales\FlashSaleResource;
use App\Models\FlashSale;
use App\Services\Promotions\FlashSaleLifecycleService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFlashSale extends EditRecord
{
    protected static string $resource = FlashSaleResource::class;

    public function getSubheading(): ?string
    {
        $status = $this->record->effectiveStatus()->getLabel();
        $regStatus = $this->record->isRegistrationOpen()
            ? 'Accepting Seller Submissions'
            : ($this->record->status === FlashSaleStatus::Reviewing ? 'Submissions Closed (In Review)' : 'Closed');

        return "Campaign Status: {$status} • Seller Participation: {$regStatus}";
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $service = app(FlashSaleLifecycleService::class);

        try {
            $service->assertEditable($this->record, $data);
        } catch (InvalidStatusTransitionException $e) {
            Notification::make()
                ->title('Cannot update campaign')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        if (in_array($this->record->status, [FlashSaleStatus::Draft, FlashSaleStatus::Reviewing], true)) {
            $now = now();
            $regStarts = ! empty($data['registration_starts_at']) ? Carbon::parse($data['registration_starts_at']) : $this->record->registration_starts_at;
            $regEnds = ! empty($data['registration_ends_at']) ? Carbon::parse($data['registration_ends_at']) : $this->record->registration_ends_at;

            if ($regStarts && $regEnds && $regStarts->lte($now) && $regEnds->gt($now)) {
                $data['status'] = FlashSaleStatus::Enrolling;
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $service = app(FlashSaleLifecycleService::class);

        return [
            Action::make('openEnrollment')
                ->label('Start Accepting Submissions')
                ->icon('heroicon-o-user-group')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Open Campaign for Seller Submissions')
                ->modalDescription('This will open the campaign for sellers to view in Seller Center and submit products.')
                ->modalSubmitActionLabel('Open Submissions')
                ->visible(fn (FlashSale $record): bool => in_array($record->status, [FlashSaleStatus::Draft, FlashSaleStatus::Reviewing], true))
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->openForEnrollment($record);
                        Notification::make()->success()->title('Campaign opened for seller participation!')->send();
                        $this->refreshFormData(['status', 'registration_starts_at', 'registration_ends_at']);
                    } catch (InvalidStatusTransitionException $e) {
                        Notification::make()->danger()->title('Cannot open enrollment')->body($e->getMessage())->send();
                    }
                }),

            Action::make('closeEnrollment')
                ->label('Close Submissions')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Close Seller Submissions')
                ->modalDescription('Are you sure you want to close seller submissions? Sellers will no longer be able to submit new products for this campaign.')
                ->modalSubmitActionLabel('Close Submissions')
                ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Enrolling)
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->closeEnrollment($record);
                        Notification::make()->success()->title('Seller enrollment closed. In review.')->send();
                        $this->refreshFormData(['status', 'registration_ends_at']);
                    } catch (InvalidStatusTransitionException $e) {
                        Notification::make()->danger()->title('Cannot close enrollment')->body($e->getMessage())->send();
                    }
                }),

            Action::make('publish')
                ->label('Publish Campaign')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->visible(fn (FlashSale $record): bool => in_array($record->status, [FlashSaleStatus::Draft, FlashSaleStatus::Enrolling, FlashSaleStatus::Reviewing], true))
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->publish($record);
                        Notification::make()->success()->title('Campaign published and scheduled!')->send();
                        $this->refreshFormData(['status']);
                    } catch (InvalidStatusTransitionException $e) {
                        Notification::make()->danger()->title('Cannot publish')->body($e->getMessage())->send();
                    }
                }),

            Action::make('startNow')
                ->label('Start Now')
                ->color('primary')
                ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Scheduled && $record->starts_at->gt(now()))
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->startNow($record);
                        Notification::make()->success()->title('Campaign started now.')->send();
                        $this->refreshFormData(['starts_at', 'status']);
                    } catch (InvalidStatusTransitionException $e) {
                        Notification::make()->danger()->title('Cannot start now')->body($e->getMessage())->send();
                    }
                }),

            Action::make('pause')
                ->label('Pause')
                ->color('warning')
                ->visible(fn (FlashSale $record): bool => in_array($record->effectiveStatus(), [FlashSaleStatus::Scheduled, FlashSaleStatus::Active], true) && $record->status !== FlashSaleStatus::Paused)
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->pause($record);
                        Notification::make()->warning()->title('Campaign paused. Prices stopped immediately.')->send();
                        $this->refreshFormData(['status', 'paused_at']);
                    } catch (InvalidStatusTransitionException $e) {
                        Notification::make()->danger()->title('Cannot pause')->body($e->getMessage())->send();
                    }
                }),

            Action::make('resume')
                ->label('Resume')
                ->color('success')
                ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Paused && $record->ends_at->gt(now()))
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->resume($record);
                        Notification::make()->success()->title('Campaign resumed.')->send();
                        $this->refreshFormData(['status', 'paused_at']);
                    } catch (InvalidStatusTransitionException $e) {
                        Notification::make()->danger()->title('Cannot resume')->body($e->getMessage())->send();
                    }
                }),

            Action::make('endNow')
                ->label('Turn off now')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Turn off flash sale now?')
                ->modalDescription('Flash prices stop immediately. Orders already placed keep their price.')
                ->visible(fn (FlashSale $record): bool => in_array($record->effectiveStatus(), [FlashSaleStatus::Active, FlashSaleStatus::Paused], true))
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->endNow($record);
                        Notification::make()->danger()->title('Campaign ended.')->send();
                        $this->refreshFormData(['status', 'ended_at']);
                    } catch (InvalidStatusTransitionException $e) {
                        Notification::make()->danger()->title('Cannot end campaign')->body($e->getMessage())->send();
                    }
                }),

            Action::make('duplicate')
                ->label('Duplicate')
                ->color('gray')
                ->action(function (FlashSale $record) use ($service): void {
                    $copy = $service->duplicate($record);
                    Notification::make()->success()->title('Draft copy created.')->send();
                    $this->redirect(FlashSaleResource::getUrl('edit', ['record' => $copy]));
                }),

            DeleteAction::make()
                ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Draft),
        ];
    }
}
