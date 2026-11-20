<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Actions;

use App\Exceptions\Accounting\SettlementException;
use App\Models\PaymentMethod;
use App\Services\Accounting\SettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * ACC-7: records money moved from a payment gateway (bKash, Nagad, card) to the bank, with the gateway's fee.
 */
class RecordGatewayTransferAction
{
    public const NAME = 'recordGatewayTransfer';

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label('Gateway transfer')
            ->icon(Heroicon::OutlinedBuildingLibrary)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading('Record a gateway transfer')
            ->modalDescription('Use this when money is moved from a bKash, Nagad or card gateway account to the bank or cashed out.')
            ->modalSubmitActionLabel('Record transfer')
            ->schema([
                TextEntry::make('held')
                    ->label('Held by payment gateways, according to the books')
                    ->state(fn (): string => '৳'.number_format(app(SettlementService::class)->gatewayClearingBalance(), 2)),
                Select::make('gateway')
                    ->label('Gateway')
                    ->options(fn (): array => PaymentMethod::query()
                        ->whereNotIn('slug', ['cod', 'mock_gateway'])
                        ->orderBy('name')
                        ->pluck('name', 'slug')
                        ->all())
                    ->required(),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('gross_amount')
                        ->label('Amount taken out of the gateway (৳)')
                        ->numeric()
                        ->minValue(0.01)
                        ->step('0.01')
                        ->required(),
                    TextInput::make('fee')
                        ->label('Fee charged (৳)')
                        ->helperText('Cash-out or settlement fee. The bank receives the amount minus this fee.')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->default(0)
                        ->required(),
                    Select::make('deposit_account')
                        ->label('Received into')
                        ->options(SettlementService::DEPOSIT_ACCOUNTS)
                        ->default('bank_operating')
                        ->required(),
                    DatePicker::make('entry_date')
                        ->label('Date received')
                        ->native(false)
                        ->default(now())
                        ->maxDate(now()->endOfDay())
                        ->required(),
                ]),
                TextInput::make('reference')
                    ->label('Transfer reference')
                    ->helperText('From the gateway or bank statement. Each reference can be recorded once per gateway.')
                    ->required()
                    ->maxLength(100),
                Checkbox::make('confirmed')
                    ->label('I have checked these amounts against the gateway and bank statements.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Tick the box to confirm before recording.']),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $entry = app(SettlementService::class)->settleGatewayTransfer(
                        gateway: (string) $data['gateway'],
                        grossAmount: (float) $data['gross_amount'],
                        fee: (float) ($data['fee'] ?? 0),
                        depositAccountSlug: (string) $data['deposit_account'],
                        entryDate: (string) $data['entry_date'],
                        reference: (string) ($data['reference'] ?? ''),
                        actor: auth()->user(),
                    );
                } catch (SettlementException $e) {
                    Notification::make()->title('Transfer not recorded')->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title("Transfer recorded as {$entry->journal_number}")->success()->send();
            });
    }
}
