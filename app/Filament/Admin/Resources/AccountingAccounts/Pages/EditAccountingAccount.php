<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingAccounts\Pages;

use App\Filament\Admin\Resources\AccountingAccounts\AccountingAccountResource;
use App\Filament\Admin\Resources\AccountingAccounts\Tables\AccountingAccountsTable;
use Filament\Resources\Pages\EditRecord;

class EditAccountingAccount extends EditRecord
{
    protected static string $resource = AccountingAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AccountingAccountsTable::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->getRecord()->is_system) {
            unset(
                $data['code'],
                $data['type'],
                $data['normal_balance'],
                $data['is_system'],
                $data['account_group_id'],
                $data['group_id']
            );
        }

        if (isset($data['account_group_id']) && ! isset($data['group_id'])) {
            $data['group_id'] = $data['account_group_id'];
        }

        return $data;
    }
}
