<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SmsLogs;

use App\Filament\Admin\Resources\SmsLogs\Pages\ListSmsLogs;
use App\Jobs\SendSmsJob;
use App\Models\SmsLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SmsLogResource extends Resource
{
    protected static ?string $model = SmsLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'SMS delivery log';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recipient_phone')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('purpose')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('driver')
                    ->badge()
                    ->color('info'),
                TextColumn::make('message')
                    ->limit(35)
                    ->tooltip(fn (SmsLog $record): string => $record->message),
                TextColumn::make('provider_response_code')
                    ->label('Code')
                    ->sortable(),
                TextColumn::make('sent_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        'pending' => 'Pending',
                        'skipped' => 'Skipped',
                    ]),
                SelectFilter::make('purpose')
                    ->options([
                        'otp_registration' => 'Registration OTP',
                        'otp_login' => 'Login OTP',
                        'order_confirmation' => 'Order Confirmation',
                        'order_invoice' => 'Invoice Link',
                        'order_status_update' => 'Status Update',
                    ]),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry Send')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (SmsLog $record): bool => $record->status === 'failed')
                    ->action(function (SmsLog $record): void {
                        SendSmsJob::dispatch(
                            phone: $record->recipient_phone,
                            message: $record->message,
                            purpose: $record->purpose,
                            bypassDeduplication: true
                        );

                        Notification::make()
                            ->title('SMS Re-dispatched to Queue')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsLogs::route('/'),
        ];
    }
}
