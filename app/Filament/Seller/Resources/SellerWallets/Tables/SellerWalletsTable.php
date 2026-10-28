<?php

namespace App\Filament\Seller\Resources\SellerWallets\Tables;

use App\Models\SellerWallet;
use App\Services\Escrow\EscrowLedgerService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SellerWalletsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('available_balance')
                    ->label('Available Balance (Tk)')
                    ->money('BDT')
                    ->weight('black')
                    ->color('success')
                    ->size('lg'),

                TextColumn::make('escrow_held_balance')
                    ->label('Escrow Held Balance (Tk)')
                    ->money('BDT')
                    ->weight('bold')
                    ->color('warning')
                    ->description('Automatically transfers 48h after delivery handover'),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M d, Y h:i A'),
            ])
            ->recordActions([
                Action::make('requestPayout')
                    ->label('Request Payout Withdrawal')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Withdrawal Amount (Tk)')
                            ->prefix('৳')
                            ->numeric()
                            ->required()
                            ->maxValue(fn (SellerWallet $record) => (float) $record->available_balance)
                            ->helperText(fn (SellerWallet $record) => 'Max available: ৳'.number_format($record->available_balance, 2)),

                        Select::make('payout_channel')
                            ->label('Payout Disbursement Channel')
                            ->options([
                                'bKash Personal / Merchant' => 'bKash (MFS)',
                                'Nagad Personal / Merchant' => 'Nagad (MFS)',
                                'Rocket' => 'Rocket (DBBL)',
                                'Bank Wire / BEFTN' => 'Bank Wire Transfer (City Bank / BRAC / EBL)',
                            ])
                            ->required(),

                        TextInput::make('account_number')
                            ->label('Account / Mobile Number')
                            ->placeholder('e.g. 017XXXXXXXX or Bank A/C #')
                            ->required(),

                        Textarea::make('note')
                            ->label('Notes / Branch Information')
                            ->placeholder('e.g. Dhanmondi Branch, A/C Name: Shahidul Alam'),
                    ])
                    ->action(function (SellerWallet $record, array $data): void {
                        $escrowService = app(EscrowLedgerService::class);
                        try {
                            $note = "{$data['payout_channel']} ({$data['account_number']}) - ".($data['note'] ?? '');
                            $escrowService->requestPayout(auth()->user(), (float) $data['amount'], $note);

                            Notification::make()
                                ->title('Payout Request Submitted')
                                ->body("Withdrawal of ৳{$data['amount']} requested. Super Admin will disburse via {$data['payout_channel']}.")
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Withdrawal Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
