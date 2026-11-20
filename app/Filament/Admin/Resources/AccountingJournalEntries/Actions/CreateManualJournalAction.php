<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingJournalEntries\Actions;

use App\DTOs\Accounting\JournalEntryData;
use App\DTOs\Accounting\JournalLineData;
use App\Enums\Accounting\AccountingEvent;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\InactiveAccountException;
use App\Exceptions\Accounting\UnbalancedJournalException;
use App\Models\Accounting\AccountingAccount;
use App\Models\Accounting\AccountingJournalEntry;
use App\Services\Accounting\JournalPostingService;
use App\Services\Audit\AuditService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * UX-ACC-1: lets an admin post a balanced manual journal (for example a bank deposit, an opening balance or a
 * correction). It is posted through `JournalPostingService` like every other journal, as a `manual_journal`, so it can
 * later be reversed from the panel (ACC-5), and it leaves an audit entry.
 */
class CreateManualJournalAction
{
    public const NAME = 'createManualJournal';

    public const MAX_LINES = 50;

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label('New manual journal')
            ->icon(Heroicon::OutlinedPlus)
            ->slideOver()
            ->modalWidth(Width::FiveExtraLarge)
            ->modalHeading('New manual journal')
            ->modalDescription('Debits and credits must be equal. A posted journal can\'t be edited or deleted; a mistake is corrected by reversing it.')
            ->modalSubmitActionLabel('Post journal')
            ->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    DatePicker::make('entry_date')
                        ->label('Journal date')
                        ->required()
                        ->native(false)
                        ->default(now())
                        ->maxDate(now()->endOfDay()),
                    TextInput::make('description')
                        ->label('What is this journal for?')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Cash deposited into the bank')
                        ->columnSpan(['md' => 2]),
                ]),
                TextInput::make('reference')
                    ->label('Supporting reference (optional)')
                    ->maxLength(100)
                    ->placeholder('e.g. bank slip or invoice number'),
                Repeater::make('lines')
                    ->label('Lines')
                    ->table([
                        TableColumn::make('Account')->markAsRequired(),
                        TableColumn::make('Debit (৳)')->width('150px'),
                        TableColumn::make('Credit (৳)')->width('150px'),
                        TableColumn::make('Note'),
                    ])
                    ->schema([
                        Select::make('account_id')
                            ->options(fn (): array => self::accountOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('debit')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01')
                            ->live(onBlur: true),
                        TextInput::make('credit')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01')
                            ->live(onBlur: true),
                        TextInput::make('narration')
                            ->maxLength(255),
                    ])
                    ->defaultItems(2)
                    ->minItems(2)
                    ->maxItems(self::MAX_LINES)
                    ->addActionLabel('Add line')
                    ->reorderable(false)
                    ->live()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $problem = self::linesProblem(is_array($value) ? $value : []);

                        if ($problem !== null) {
                            $fail($problem);
                        }
                    }),
                TextEntry::make('totals')
                    ->label('Totals')
                    ->state(fn (Get $get): string => self::totalsSummary((array) ($get('lines') ?? []))),
                Checkbox::make('confirmed')
                    ->label('I have checked the accounts and amounts. Post this journal permanently.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Tick the box to confirm before posting.']),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $entry = app(JournalPostingService::class)->post(new JournalEntryData(
                        entryDate: (string) $data['entry_date'],
                        financialEvent: AccountingEvent::MANUAL_JOURNAL->value,
                        idempotencyKey: 'manual_journal_'.Str::uuid()->toString(),
                        description: (string) $data['description'],
                        lines: array_map(
                            fn (array $line): JournalLineData => new JournalLineData(
                                accountId: (int) $line['account_id'],
                                debit: self::amount($line['debit'] ?? null),
                                credit: self::amount($line['credit'] ?? null),
                                narration: filled($line['narration'] ?? null) ? (string) $line['narration'] : null,
                            ),
                            array_values($data['lines']),
                        ),
                        createdByUserId: auth()->id(),
                        metadata: array_filter(['reference' => $data['reference'] ?? null], filled(...)),
                    ));
                } catch (ClosedPeriodException|InactiveAccountException|UnbalancedJournalException|InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Journal not posted')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                self::audit($entry, $data);

                Notification::make()
                    ->title("Journal {$entry->journal_number} posted")
                    ->success()
                    ->send();
            });
    }

    /**
     * Returns a plain-language reason the lines can't be posted, or null when they balance.
     *
     * @param  array<array-key, array<string, mixed>>  $lines
     */
    public static function linesProblem(array $lines): ?string
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $number = 0;

        foreach ($lines as $line) {
            $number++;
            $debit = self::amount($line['debit'] ?? null);
            $credit = self::amount($line['credit'] ?? null);

            if ($debit < 0 || $credit < 0) {
                return "Line {$number}: amounts can't be negative.";
            }

            if ($debit > 0 && $credit > 0) {
                return "Line {$number}: enter either a debit or a credit, not both.";
            }

            if ($debit === 0.0 && $credit === 0.0) {
                return "Line {$number}: enter a debit or a credit amount.";
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (bccomp(sprintf('%.2f', $totalDebit), sprintf('%.2f', $totalCredit), 2) !== 0) {
            return sprintf(
                'Debits (৳%s) and credits (৳%s) must be equal. The difference is ৳%s.',
                number_format($totalDebit, 2),
                number_format($totalCredit, 2),
                number_format(abs($totalDebit - $totalCredit), 2),
            );
        }

        return null;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $lines
     */
    private static function totalsSummary(array $lines): string
    {
        $totalDebit = array_sum(array_map(fn (array $line): float => self::amount($line['debit'] ?? null), $lines));
        $totalCredit = array_sum(array_map(fn (array $line): float => self::amount($line['credit'] ?? null), $lines));
        $difference = round($totalDebit - $totalCredit, 2);

        if ($totalDebit === 0.0 && $totalCredit === 0.0) {
            return 'Enter a debit or credit amount on each line.';
        }

        return sprintf(
            'Debit ৳%s · Credit ৳%s · %s',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2),
            $difference === 0.0 ? 'Balanced' : 'Off by ৳'.number_format(abs($difference), 2),
        );
    }

    private static function amount(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    /**
     * @return array<int, string>
     */
    private static function accountOptions(): array
    {
        return AccountingAccount::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (AccountingAccount $account): array => [$account->id => "{$account->code} · {$account->name}"])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function audit(AccountingJournalEntry $entry, array $data): void
    {
        app(AuditService::class)->record([
            'module' => 'accounting',
            'action' => 'manual_journal_posted',
            'description' => "Posted manual journal {$entry->journal_number}: {$entry->description}",
            'auditable_type' => AccountingJournalEntry::class,
            'auditable_id' => $entry->id,
            'status' => 'success',
            'metadata' => [
                'journal_number' => $entry->journal_number,
                'entry_date' => (string) $data['entry_date'],
                'reference' => $data['reference'] ?? null,
                'total' => (float) $entry->lines->sum('debit'),
                'lines' => $entry->lines->count(),
            ],
            'sync' => true,
        ]);
    }
}
