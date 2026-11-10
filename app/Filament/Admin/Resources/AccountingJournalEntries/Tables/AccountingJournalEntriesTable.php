<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Tables;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\SettlementException;
use App\Models\Accounting\AccountingJournalEntry;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\SettlementService;
use App\Services\Formatting\Money;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountingJournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lines.account', 'reversalJournal'])->withExists('reversedEntries'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('entry_number')
                    ->label('Entry Number')
                    ->weight('bold')
                    ->copyable()
                    ->state(fn (AccountingJournalEntry $record): string => $record->journal_number)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('journal_number', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('journal_number', $direction)),

                TextColumn::make('entry_date')
                    ->label('Entry Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('financial_event')
                    ->label('Financial Event')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => str((string) $state)->headline()->toString()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst($state instanceof JournalEntryStatus ? $state->value : (string) $state))
                    ->color(fn ($state): string => match ($state instanceof JournalEntryStatus ? $state->value : (string) $state) {
                        JournalEntryStatus::POSTED->value => 'success',
                        JournalEntryStatus::REVERSED->value => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('total_debit')
                    ->label('Total (৳)')
                    ->formatStateUsing(fn ($state): string => Money::taka($state))
                    ->state(fn (AccountingJournalEntry $record): float => (float) $record->lines->sum('debit'))
                    ->weight('bold'),

                TextColumn::make('description')
                    ->label('Description')
                    ->wrap()
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('reversalJournal.entry_number')
                    ->label('Reversed By')
                    ->placeholder('—')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('d M Y, g:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(
                        collect(JournalEntryStatus::cases())
                            ->mapWithKeys(fn (JournalEntryStatus $status): array => [$status->value => ucfirst($status->value)])
                            ->all()
                    ),

                SelectFilter::make('financial_event')
                    ->label('Financial Event')
                    ->options(
                        collect(AccountingEvent::cases())
                            ->mapWithKeys(fn (AccountingEvent $event): array => [$event->value => str($event->value)->headline()->toString()])
                            ->prepend('Reversal', 'reversal')
                            ->all()
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                self::reverseAction(),
                self::undoSettlementAction(),
            ]);
    }

    /**
     * Only manual journals can be reversed here (ACC-5). A system journal mirrors a wallet, escrow or payout
     * movement, so it is undone through that business action, which keeps the money and the books in step.
     */
    public static function reverseAction(): Action
    {
        return Action::make('reverse')
            ->label('Reverse')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(function (?AccountingJournalEntry $record): bool {
                if (! $record || $record->financial_event !== AccountingEvent::MANUAL_JOURNAL->value) {
                    return false;
                }

                $status = $record->status instanceof JournalEntryStatus
                    ? $record->status
                    : JournalEntryStatus::tryFrom((string) $record->status);

                $isReversal = $record->relationLoaded('reversedEntries')
                    ? $record->reversedEntries->isNotEmpty()
                    : (bool) ($record->reversed_entries_exists ?? $record->reversedEntries()->exists());

                return $status === JournalEntryStatus::POSTED
                    && ! $record->reversal_journal_id
                    && ! $isReversal;
            })
            ->requiresConfirmation()
            ->modalHeading('Reverse Journal Entry')
            ->modalDescription('Are you sure you want to reverse this journal entry? An inverse balancing journal entry will be posted atomically.')
            ->modalSubmitActionLabel('Reverse Entry')
            ->schema([
                TextInput::make('reason')
                    ->label('Reversal Reason')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Booking adjustment'),
            ])
            ->action(function (AccountingJournalEntry $record, array $data): void {
                app(JournalPostingService::class)->reverse(
                    $record,
                    $data['reason'],
                    auth()->user(),
                );

                Notification::make()
                    ->title('Journal entry reversed successfully')
                    ->success()
                    ->send();
            });
    }

    /**
     * I-1: a courier remittance or gateway transfer recorded by mistake is undone through the settlement service, so
     * the parcels go back on the unsettled list and the transfer reference can be used again.
     */
    public static function undoSettlementAction(): Action
    {
        return Action::make('undoSettlement')
            ->label('Undo settlement')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (?AccountingJournalEntry $record): bool => $record !== null
                && SettlementService::isUndoableSettlement($record)
                && ! $record->reversal_journal_id)
            ->requiresConfirmation()
            ->modalHeading('Undo this settlement?')
            ->modalDescription('A reversing journal is posted. A courier remittance\'s parcels go back on the unsettled list; a gateway transfer\'s reference can be recorded again. Record the correct settlement afterwards.')
            ->modalSubmitActionLabel('Undo settlement')
            ->schema([
                TextInput::make('reason')
                    ->label('Why is it being undone?')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Wrong amount entered'),
            ])
            ->action(function (AccountingJournalEntry $record, array $data, Action $action): void {
                try {
                    $reversal = app(SettlementService::class)->undoSettlement($record, (string) $data['reason'], auth()->user());
                } catch (SettlementException $e) {
                    Notification::make()->title('Settlement not undone')->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title("Settlement {$record->journal_number} undone")
                    ->body("Reversal journal {$reversal->journal_number} was posted.")
                    ->success()
                    ->send();
            });
    }
}
