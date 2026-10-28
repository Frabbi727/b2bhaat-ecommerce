<?php

namespace App\Filament\Seller\Resources\RfqRequests\Tables;

use App\Models\RfqRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('send_quote')
                    ->label('Send Quote')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->form([
                        TextInput::make('seller_quoted_price')
                            ->label('Quoted Price per Unit (Tk ৳)')
                            ->prefix('৳')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(fn (RfqRequest $record) => $record->seller_quoted_price ?? $record->target_unit_price),
                        Textarea::make('seller_notes')
                            ->label('Quotation Terms & Delivery Timeline')
                            ->placeholder('e.g. Valid for 14 days. Minimum 50% advance for custom branding...')
                            ->default(fn (RfqRequest $record) => $record->seller_notes),
                    ])
                    ->action(function (RfqRequest $record, array $data) {
                        $record->update([
                            'seller_quoted_price' => $data['seller_quoted_price'],
                            'seller_notes' => $data['seller_notes'] ?? null,
                            'status' => 'quoted',
                        ]);

                        Notification::make()
                            ->title('Quotation Dispatched')
                            ->body("Your custom quote of ৳{$data['seller_quoted_price']}/unit has been recorded.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
