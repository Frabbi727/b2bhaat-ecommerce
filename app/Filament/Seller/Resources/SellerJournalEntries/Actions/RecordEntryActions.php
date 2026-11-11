<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\SellerJournalEntries\Actions;

use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerAccounting\SellerJournalEntry;
use App\Services\SellerAccounting\SellerChartOfAccountsService;
use App\Services\SellerAccounting\SellerManualEntryService;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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

/**
 * Seller books: the "Record" menu — everything a seller writes into their own books by hand. Each action posts one
 * balanced entry through SellerManualEntryService.
 */
class RecordEntryActions
{
    public const MAX_LINES = 30;

    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            self::investment(),
            self::withdrawal(),
            self::expense(),
            self::stockPurchase(),
            self::supplierPayment(),
            self::otherIncome(),
            self::openingBalances(),
            self::manualJournal(),
        ])
            ->label('Record')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->button();
    }

    public static function investment(): Action
    {
        return self::simple('recordInvestment', 'Money invested', Heroicon::OutlinedBanknotes,
            'Money you (or a partner) put into the business. It increases your capital.',
            moneyLabel: 'Received into',
            post: fn (SellerManualEntryService $service, int $sellerId, array $data): SellerJournalEntry => $service->recordCapital(
                $sellerId, $sellerId, (float) $data['amount'], (string) $data['entry_date'], (int) $data['money_account_id'], $data['note'] ?? null));
    }

    public static function withdrawal(): Action
    {
        return self::simple('recordWithdrawal', 'Owner withdrawal', Heroicon::OutlinedArrowUpOnSquare,
            'Money you took out of the business for personal use. It is not a business expense, so it does not reduce profit.',
            moneyLabel: 'Paid from',
            post: fn (SellerManualEntryService $service, int $sellerId, array $data): SellerJournalEntry => $service->recordDrawing(
                $sellerId, $sellerId, (float) $data['amount'], (string) $data['entry_date'], (int) $data['money_account_id'], $data['note'] ?? null));
    }

    public static function expense(): Action
    {
        return self::base('recordExpense', 'Expense', Heroicon::OutlinedReceiptPercent,
            'Rent, salaries, packaging, transport, bills… Choose "Supplier dues" if you have not paid it yet.')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('expense_account_id')
                        ->label('Expense type')
                        ->options(fn (): array => self::chart()->expenseAccountOptions(self::sellerId()))
                        ->required()
                        ->native(false)
                        ->helperText('Add your own types in Chart of accounts.'),
                    self::amountInput(),
                    Select::make('paid_from_account_id')
                        ->label('Paid from')
                        ->options(fn (): array => self::paymentSourceOptions())
                        ->default(fn (): ?int => self::accountId(SellerChartOfAccountsService::CASH))
                        ->required()
                        ->native(false),
                    self::dateInput(),
                ]),
                self::noteInput('e.g. Shop rent for November'),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, 'Expense recorded', fn (SellerManualEntryService $service, int $sellerId): SellerJournalEntry => $service->recordExpense(
                $sellerId, $sellerId, (float) $data['amount'], (string) $data['entry_date'], (int) $data['expense_account_id'], (int) $data['paid_from_account_id'], $data['note'] ?? null)));
    }

    public static function stockPurchase(): Action
    {
        return self::base('recordStockPurchase', 'Stock purchase', Heroicon::OutlinedArchiveBoxArrowDown,
            'Goods you bought to sell. They go into your stock value and become cost of goods sold as they sell. (This does not change the stock count of your products — use "Adjust stock" on the product for that.)')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    self::amountInput('Total cost'),
                    Select::make('paid_from_account_id')
                        ->label('Paid from')
                        ->options(fn (): array => self::paymentSourceOptions())
                        ->default(fn (): ?int => self::accountId(SellerChartOfAccountsService::CASH))
                        ->required()
                        ->native(false),
                    self::dateInput(),
                ]),
                self::noteInput('e.g. 200 shirts from Narayanganj factory, invoice 4512'),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, 'Stock purchase recorded', fn (SellerManualEntryService $service, int $sellerId): SellerJournalEntry => $service->recordStockPurchase(
                $sellerId, $sellerId, (float) $data['amount'], (string) $data['entry_date'], (int) $data['paid_from_account_id'], $data['note'] ?? null)));
    }

    public static function supplierPayment(): Action
    {
        return self::simple('recordSupplierPayment', 'Supplier payment', Heroicon::OutlinedTruck,
            'Paying off stock or expenses you recorded earlier as owed to a supplier.',
            moneyLabel: 'Paid from',
            post: fn (SellerManualEntryService $service, int $sellerId, array $data): SellerJournalEntry => $service->recordSupplierPayment(
                $sellerId, $sellerId, (float) $data['amount'], (string) $data['entry_date'], (int) $data['money_account_id'], $data['note'] ?? null));
    }

    public static function otherIncome(): Action
    {
        return self::simple('recordOtherIncome', 'Other income', Heroicon::OutlinedSparkles,
            'Income from outside your B2Bhaat orders, e.g. selling scrap or a supplier rebate. B2Bhaat sales are recorded automatically.',
            moneyLabel: 'Received into',
            post: fn (SellerManualEntryService $service, int $sellerId, array $data): SellerJournalEntry => $service->recordOtherIncome(
                $sellerId, $sellerId, (float) $data['amount'], (string) $data['entry_date'], (int) $data['money_account_id'], $data['note'] ?? null));
    }

    public static function openingBalances(): Action
    {
        return self::base('recordOpeningBalances', 'Opening balances', Heroicon::OutlinedFlag,
            'What your business already had on the day you start keeping books here. Fill in only what applies.')
            ->schema([
                self::dateInput('As of'),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    self::optionalAmount('cash', 'Cash in hand'),
                    self::optionalAmount('bank', 'Bank & mobile banking'),
                    self::optionalAmount('inventory', 'Stock value at cost')
                        ->default(fn (): ?float => ($value = app(SellerManualEntryService::class)->stockValueAtCost(self::sellerId())) > 0 ? $value : null)
                        ->helperText('Suggested: units in stock × your cost per unit.'),
                    self::optionalAmount('supplier_dues', 'Owed to suppliers'),
                    self::optionalAmount('loans', 'Loans'),
                ]),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, 'Opening balances recorded', fn (SellerManualEntryService $service, int $sellerId): SellerJournalEntry => $service->recordOpeningBalances(
                $sellerId, $sellerId, (string) $data['entry_date'], $data)));
    }

    public static function manualJournal(): Action
    {
        return self::base('recordManualJournal', 'Manual journal', Heroicon::OutlinedPencilSquare,
            'For accountants: any entry where total debits equal total credits. The B2Bhaat wallet accounts are filled automatically and are not listed.')
            ->slideOver()
            ->modalWidth(Width::FiveExtraLarge)
            ->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    self::dateInput(),
                    TextInput::make('description')
                        ->label('What is this entry for?')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(['md' => 2]),
                ]),
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
                            ->options(fn (): array => self::chart()->manualAccountOptions(self::sellerId()))
                            ->searchable()
                            ->required(),
                        TextInput::make('debit')->numeric()->minValue(0)->step('0.01')->live(onBlur: true),
                        TextInput::make('credit')->numeric()->minValue(0)->step('0.01')->live(onBlur: true),
                        TextInput::make('narration')->maxLength(255),
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
            ])
            ->action(fn (array $data, Action $action) => self::run($action, 'Journal recorded', fn (SellerManualEntryService $service, int $sellerId): SellerJournalEntry => $service->postManualJournal(
                $sellerId, $sellerId, (string) $data['entry_date'], (string) $data['description'], array_values($data['lines']))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function linesProblem(array $lines): ?string
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $lineDebit = (int) round(((float) ($line['debit'] ?? 0)) * 100);
            $lineCredit = (int) round(((float) ($line['credit'] ?? 0)) * 100);
            if ($lineDebit > 0 && $lineCredit > 0) {
                return 'Each line is either a debit or a credit, not both.';
            }
            $debit += $lineDebit;
            $credit += $lineCredit;
        }

        if ($debit === 0) {
            return 'Enter the amounts.';
        }

        return $debit === $credit ? null : sprintf('Debits (৳%s) and credits (৳%s) must be equal.', number_format($debit / 100, 2), number_format($credit / 100, 2));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private static function totalsSummary(array $lines): string
    {
        $debit = array_sum(array_map(fn (array $line): float => (float) ($line['debit'] ?? 0), $lines));
        $credit = array_sum(array_map(fn (array $line): float => (float) ($line['credit'] ?? 0), $lines));
        $difference = round($debit - $credit, 2);

        return sprintf('Debit ৳%s · Credit ৳%s · %s', number_format($debit, 2), number_format($credit, 2),
            $difference == 0.0 ? 'Balanced' : 'Off by ৳'.number_format(abs($difference), 2));
    }

    /**
     * An entry that is just an amount, a money account, a date and a note.
     *
     * @param  Closure(SellerManualEntryService, int, array<string, mixed>): SellerJournalEntry  $post
     */
    private static function simple(string $name, string $label, Heroicon $icon, string $description, string $moneyLabel, Closure $post): Action
    {
        return self::base($name, $label, $icon, $description)
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    self::amountInput(),
                    Select::make('money_account_id')
                        ->label($moneyLabel)
                        ->options(fn (): array => self::chart()->moneyAccountOptions(self::sellerId()))
                        ->default(fn (): ?int => self::accountId(SellerChartOfAccountsService::CASH))
                        ->required()
                        ->native(false),
                    self::dateInput(),
                ]),
                self::noteInput(),
            ])
            ->action(fn (array $data, Action $action) => self::run($action, "{$label} recorded", fn (SellerManualEntryService $service, int $sellerId): SellerJournalEntry => $post($service, $sellerId, $data)));
    }

    private static function base(string $name, string $label, Heroicon $icon, string $description): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->modalHeading($label)
            ->modalDescription($description)
            ->modalSubmitActionLabel('Record')
            ->modalWidth(Width::TwoExtraLarge);
    }

    /**
     * @param  Closure(SellerManualEntryService, int): SellerJournalEntry  $post
     */
    private static function run(Action $action, string $title, Closure $post): void
    {
        try {
            $entry = $post(app(SellerManualEntryService::class), self::sellerId());
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();
            $action->halt();

            return;
        }

        Notification::make()->success()->title($title)->body("Saved as {$entry->entry_number}.")->send();
    }

    private static function amountInput(string $label = 'Amount'): TextInput
    {
        return TextInput::make('amount')
            ->label($label)
            ->prefix('৳')
            ->numeric()
            ->minValue(0.01)
            ->maxValue(999999999)
            ->step('0.01')
            ->required();
    }

    private static function optionalAmount(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->prefix('৳')->numeric()->minValue(0)->maxValue(999999999)->step('0.01');
    }

    private static function dateInput(string $label = 'Date'): DatePicker
    {
        return DatePicker::make('entry_date')
            ->label($label)
            ->required()
            ->native(false)
            ->default(now(config('app.display_timezone'))->toDateString())
            ->maxDate(now(config('app.display_timezone'))->endOfDay());
    }

    private static function noteInput(string $placeholder = 'Optional'): TextInput
    {
        return TextInput::make('note')->label('Note')->placeholder($placeholder)->maxLength(255);
    }

    /**
     * @return array<int, string>
     */
    private static function paymentSourceOptions(): array
    {
        $options = self::chart()->moneyAccountOptions(self::sellerId());
        $dues = SellerAccount::query()->where('seller_id', self::sellerId())->where('slug', SellerChartOfAccountsService::ACCOUNTS_PAYABLE)->first();
        if ($dues) {
            $options[$dues->id] = 'Not paid yet (supplier dues)';
        }

        return $options;
    }

    private static function accountId(string $slug): ?int
    {
        return self::chart()->accountIds(self::sellerId())[$slug] ?? null;
    }

    private static function chart(): SellerChartOfAccountsService
    {
        return app(SellerChartOfAccountsService::class);
    }

    private static function sellerId(): int
    {
        return (int) auth()->id();
    }
}
