<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Actions;

use App\Exceptions\Accounting\SettlementException;
use App\Services\Accounting\SettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * ACC-7: records a courier's COD remittance. The admin picks the delivered parcels it covers and enters what reached
 * the bank and what the courier kept as charges; the two must add up to what the parcels collected.
 */
class RecordCourierRemittanceAction
{
    public const NAME = 'recordCourierRemittance';

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label('Courier remittance')
            ->icon(Heroicon::OutlinedTruck)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalHeading('Record a courier remittance')
            ->modalDescription('Use this when a courier pays out the cash it collected on delivered COD parcels. Tick the parcels in the courier\'s payment statement.')
            ->modalSubmitActionLabel('Record remittance')
            ->schema([
                Select::make('courier')
                    ->label('Courier')
                    ->options(fn (): array => app(SettlementService::class)->couriersWithUnsettledParcels())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('sub_order_ids', []))
                    ->helperText('Only couriers with delivered COD parcels waiting for payment are listed.'),
                CheckboxList::make('sub_order_ids')
                    ->label('Parcels in this payment')
                    ->options(fn (Get $get): array => self::parcelOptions($get('courier')))
                    ->required()
                    ->bulkToggleable()
                    ->searchable()
                    ->live()
                    ->visible(fn (Get $get): bool => filled($get('courier')))
                    ->columns(1),
                TextEntry::make('collected')
                    ->label('Collected on the ticked parcels')
                    ->state(fn (Get $get): string => '৳'.number_format(self::collectedTotal($get('courier'), (array) ($get('sub_order_ids') ?? [])), 2))
                    ->visible(fn (Get $get): bool => filled($get('courier'))),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('received_amount')
                        ->label('Amount received (৳)')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->required(),
                    TextInput::make('courier_charges')
                        ->label('Courier charges deducted (৳)')
                        ->helperText('Delivery and COD charges the courier kept.')
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
                    ->label('Courier payment reference (optional)')
                    ->maxLength(100),
                Checkbox::make('confirmed')
                    ->label('I have checked these parcels and amounts against the courier\'s statement.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Tick the box to confirm before recording.']),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $entry = app(SettlementService::class)->settleCourierRemittance(
                        subOrderIds: array_map('intval', (array) $data['sub_order_ids']),
                        receivedAmount: (float) $data['received_amount'],
                        courierCharges: (float) ($data['courier_charges'] ?? 0),
                        depositAccountSlug: (string) $data['deposit_account'],
                        entryDate: (string) $data['entry_date'],
                        reference: filled($data['reference'] ?? null) ? (string) $data['reference'] : null,
                        actor: auth()->user(),
                    );
                } catch (SettlementException $e) {
                    Notification::make()->title('Remittance not recorded')->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title("Remittance recorded as {$entry->journal_number}")->success()->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private static function parcelOptions(?string $courier): array
    {
        if (blank($courier)) {
            return [];
        }

        return app(SettlementService::class)->unsettledCourierParcels($courier)
            ->mapWithKeys(fn (array $parcel): array => [
                $parcel['sub_order_id'] => sprintf('%s · ৳%s · delivered %s', $parcel['sub_order_number'], number_format($parcel['amount'], 2), $parcel['delivered_on']),
            ])
            ->all();
    }

    /**
     * @param  array<array-key, int|string>  $subOrderIds
     */
    private static function collectedTotal(?string $courier, array $subOrderIds): float
    {
        if (blank($courier) || $subOrderIds === []) {
            return 0.0;
        }

        $ids = array_map('intval', $subOrderIds);

        return round((float) app(SettlementService::class)->unsettledCourierParcels($courier)
            ->whereIn('sub_order_id', $ids)
            ->sum('amount'), 2);
    }
}
