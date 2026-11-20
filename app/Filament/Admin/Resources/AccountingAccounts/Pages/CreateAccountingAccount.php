<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountingAccounts\Pages;

use App\Filament\Admin\Resources\AccountingAccounts\AccountingAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountingAccount extends CreateRecord
{
    protected static string $resource = AccountingAccountResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_system'] = false;

        if (isset($data['account_group_id']) && ! isset($data['group_id'])) {
            $data['group_id'] = $data['account_group_id'];
        }

        return $data;
    }
}
