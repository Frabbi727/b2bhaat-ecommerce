<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Pages;

use App\Enums\FlashSaleStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Filament\Admin\Resources\FlashSales\FlashSaleResource;
use App\Models\FlashSale;
use App\Services\Promotions\FlashSaleLifecycleService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFlashSale extends EditRecord
{
    protected static string $resource = FlashSaleResource::class;

    protected function beforeSave(): void
    {
        $service = app(FlashSaleLifecycleService::class);
        $service->assertEditable($this->record, $this->data);
    }

    protected function getHeaderActions(): array
    {
        $service = app(FlashSaleLifecycleService::class);

        return [
            Action::make('publish')
                ->label('Publish Campaign')
                ->color('success')
                ->visible(fn (FlashSale $record): bool => $record->status === FlashSaleStatus::Draft)
                ->action(function (FlashSale $record) use ($service): void {
                    try {
                        $service->publish($record);
                        Notification::make()->success()->title('Campaign published and scheduled.')->send();
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
