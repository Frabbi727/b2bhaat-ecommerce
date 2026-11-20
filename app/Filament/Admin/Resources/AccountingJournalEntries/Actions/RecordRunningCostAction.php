<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Actions;

use App\Exceptions\Accounting\SettlementException;
use App\Services\Accounting\RunningCostService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

/**
 * F7: the owner's "Record a cost" form, on the Money page and the journal entries list.
 */
class RecordRunningCostAction
{
    public const NAME = 'recordRunningCost';

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label('Record a cost')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::ExtraLarge)
            ->modalHeading('Record a running cost')
            ->modalDescription('Money B2Bhaat paid for running the business, such as hosting, SMS top-ups, staff or ads. It is taken off profit and off the account it was paid from.')
            ->modalSubmitActionLabel('Record cost')
            ->schema([
                Hidden::make('submission_key')->default(fn (): string => (string) Str::uuid()),
                Select::make('category')
                    ->label('What was it for?')
                    ->options(RunningCostService::CATEGORIES)
                    ->required(),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('amount')
                        ->label('Amount (৳)')
                        ->numeric()
                        ->minValue(0.01)
                        ->step('0.01')
                        ->required(),
                    DatePicker::make('date')
                        ->label('Date paid')
                        ->native(false)
                        ->default(now(config('app.display_timezone')))
                        ->maxDate(now(config('app.display_timezone'))->endOfDay())
                        ->required(),
                ]),
                Select::make('paid_from')
                    ->label('Paid from')
                    ->options(RunningCostService::PAID_FROM)
                    ->default('bank_operating')
                    ->required(),
                TextInput::make('note')
                    ->label('Note (optional)')
                    ->placeholder('e.g. November hosting, BulkSMSBD 5,000 SMS')
                    ->maxLength(150),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $entry = app(RunningCostService::class)->record(
                        category: (string) $data['category'],
                        amount: (float) $data['amount'],
                        paidFrom: (string) $data['paid_from'],
                        date: (string) $data['date'],
                        note: (string) ($data['note'] ?? ''),
                        actor: auth()->user(),
                        submissionKey: (string) ($data['submission_key'] ?? Str::uuid()),
                    );
                } catch (SettlementException $e) {
                    Notification::make()->title('Cost not recorded')->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title('Cost not recorded')->body('The books refused this entry, for example because that month is closed. Nothing was recorded.')->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title("Cost recorded as {$entry->journal_number}")->body('If it was wrong, reverse it from Journal entries.')->success()->send();
            });
    }
}
