<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\RfqRequests\Tables;

use App\Models\RfqRequest;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RfqRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Inquiry Date')
                    ->dateTime('d M, Y')
                    ->sortable(),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->weight('bold')
                    ->limit(28),
                TextColumn::make('buyer_name')
                    ->label('Buyer')
                    ->searchable(),
                TextColumn::make('buyer_phone')
                    ->label('Contact')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('company_name')
                    ->label('Company / Shop')
                    ->placeholder('Individual')
                    ->searchable(),
                TextColumn::make('target_quantity')
                    ->label('Target Qty')
                    ->numeric()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('target_unit_price')
                    ->label('Target Price')
                    ->money('BDT')
                    ->placeholder('Open to quote')
                    ->sortable(),
                TextColumn::make('destination_district')
                    ->label('District')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (RfqRequest $record): array => match ($record->status) {
                        'accepted' => Color::Emerald,
                        'quoted' => Color::Blue,
                        'pending' => Color::Amber,
                        'rejected' => Color::Red,
                        default => Color::Gray,
                    }),
                TextColumn::make('seller_quoted_price')
                    ->label('Your Quoted Price')
                    ->money('BDT')
                    ->placeholder('Awaiting quote')
                    ->sortable(),
                TextColumn::make('dispatch_days')
                    ->label('Lead Time')
                    ->suffix(' days')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label('Quote Validity')
                    ->dateTime('d M, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('send_quote')
                    ->label('Send Quote')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (RfqRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->form([
                        TextInput::make('seller_quoted_price')
                            ->label('Quoted Price per Unit (Tk ৳)')
                            ->prefix('৳')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(fn (RfqRequest $record) => $record->seller_quoted_price ?? $record->target_unit_price),
                        Select::make('validity_days')
                            ->label('Quote Validity')
                            ->options([
                                7 => '7 Days',
                                14 => '14 Days (Standard)',
                                30 => '30 Days (Extended)',
                            ])
                            ->default(14)
                            ->required(),
                        TextInput::make('dispatch_days')
                            ->label('Production & Dispatch Timeline (Days)')
                            ->numeric()
                            ->minValue(1)
                            ->default(fn (RfqRequest $record) => $record->dispatch_days ?? 7)
                            ->required(),
                        Textarea::make('seller_notes')
                            ->label('Quotation Terms & Delivery Timeline')
                            ->placeholder('e.g. Valid for 14 days. Minimum 50% advance for custom branding...')
                            ->default(fn (RfqRequest $record) => $record->seller_notes),
                    ])
                    ->action(function (RfqRequest $record, array $data) {
                        $validUntil = now()->addDays((int) $data['validity_days'])->endOfDay();

                        $record->update([
                            'seller_quoted_price' => $data['seller_quoted_price'],
                            'seller_notes' => $data['seller_notes'] ?? null,
                            'valid_until' => $validUntil,
                            'dispatch_days' => (int) $data['dispatch_days'],
                            'status' => 'quoted',
                        ]);

                        $formattedPrice = number_format((float) $data['seller_quoted_price'], 2);

                        Notification::make()
                            ->title('Quotation Dispatched')
                            ->body("Your custom quote of ৳{$formattedPrice}/unit has been recorded.")
                            ->success()
                            ->send();
                    }),

                Action::make('decline_inquiry')
                    ->label('Decline Inquiry')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (RfqRequest $record): bool => $record->status === 'pending' && (auth()->user()?->can('update', $record) ?? false))
                    ->form([
                        Select::make('reason_preset')
                            ->label('Decline Reason')
                            ->live()
                            ->options([
                                'capacity' => 'Factory production capacity is full',
                                'moq' => 'Target quantity is below our minimum batch run',
                                'spec' => 'Custom specifications cannot be accommodated',
                                'price' => 'Target budget is unfeasible for current raw material costs',
                                'other' => 'Other reason',
                            ])
                            ->default('capacity')
                            ->required(fn (Get $get): bool => blank($get('rejection_reason'))),
                        Textarea::make('rejection_reason')
                            ->label('Detailed Reason / Explanation')
                            ->placeholder('Provide additional context for the buyer...')
                            ->maxLength(1000)
                            ->required(fn (Get $get): bool => $get('reason_preset') === 'other'),
                    ])
                    ->action(function (RfqRequest $record, array $data) {
                        $presets = [
                            'capacity' => 'Factory production capacity is full',
                            'moq' => 'Target quantity is below our minimum batch run',
                            'spec' => 'Custom specifications cannot be accommodated',
                            'price' => 'Target budget is unfeasible for current raw material costs',
                            'other' => 'Other reason',
                        ];

                        $presetKey = $data['reason_preset'] ?? null;
                        $presetText = $presets[$presetKey] ?? null;
                        $customReason = filled($data['rejection_reason'] ?? null) ? trim((string) $data['rejection_reason']) : null;

                        $reason = match (true) {
                            $presetKey === 'other' => $customReason ?? 'Other reason',
                            filled($customReason) && filled($presetText) && ! str_contains($customReason, $presetText) => "{$presetText}: {$customReason}",
                            filled($customReason) => $customReason,
                            default => $presetText ?? 'Declined by seller',
                        };

                        $record->update([
                            'status' => 'rejected',
                            'rejected_by' => 'seller',
                            'rejection_reason' => $reason,
                        ]);

                        Notification::make()
                            ->title('Inquiry Declined')
                            ->body('The wholesale RFQ has been declined.')
                            ->danger()
                            ->send();
                    }),

                Action::make('print_quotation')
                    ->label('Print Quotation')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (RfqRequest $record): string => route('rfq.quotation.print', $record->id))
                    ->openUrlInNewTab()
                    ->visible(fn (RfqRequest $record): bool => in_array($record->status, ['quoted', 'accepted'])),

                EditAction::make(),
            ]);
        // No delete: RFQs belong to the buyer (PRA-012)
    }
}
