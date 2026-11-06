<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SellerPlans\Pages;

use App\Filament\Admin\Resources\SellerPlans\SellerPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSellerPlan extends EditRecord
{
    protected static string $resource = SellerPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
