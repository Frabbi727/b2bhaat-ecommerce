<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\SellerAccounts\Pages;

use App\Enums\Accounting\AccountType;
use App\Filament\Seller\Resources\SellerAccounts\SellerAccountResource;
use App\Services\SellerAccounting\SellerChartOfAccountsService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSellerAccounts extends ListRecords
{
    protected static string $resource = SellerAccountResource::class;

    protected ?string $subheading = 'Click an account to see every movement in it.';

    public function mount(): void
    {
        // The standard accounts exist from the first visit, before any entry is posted
        app(SellerChartOfAccountsService::class)->ensureFor((int) auth()->id());

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addAccount')
                ->label('Add account')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Add an account')
                ->modalDescription('Most sellers add expense types (e.g. "Factory electricity") or another money account (e.g. "bKash merchant wallet").')
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(100),
                    Select::make('type')
                        ->label('Kind of account')
                        ->options([
                            AccountType::EXPENSE->value => 'Expense — a cost of running the business',
                            AccountType::ASSET->value => 'Money or asset — cash, bank, mobile wallet, equipment',
                            AccountType::REVENUE->value => 'Income — money earned outside B2Bhaat orders',
                            AccountType::LIABILITY->value => 'Liability — money you owe',
                            AccountType::EQUITY->value => 'Capital — a partner\'s investment',
                        ])
                        ->default(AccountType::EXPENSE->value)
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $account = app(SellerChartOfAccountsService::class)->addAccount((int) auth()->id(), (string) $data['name'], AccountType::from((string) $data['type']));
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title('Account not added')->body($e->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title("Added {$account->code} · {$account->name}")->send();
                }),
        ];
    }
}
