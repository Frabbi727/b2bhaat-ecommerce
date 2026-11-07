<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Tables;

use App\Enums\Accounting\AccountingEvent;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Accounting\AccountingJournalEntry;
use App\Services\Accounting\JournalPostingService;
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
                    ->label('Total (Tk)')
                    ->money('BDT')
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
            ]);
    }

    public static function reverseAction(): Action
    {
        return Action::make('reverse')
            ->label('Reverse')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(function (?AccountingJournalEntry $record): bool {
                if (! $record) {
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
}
