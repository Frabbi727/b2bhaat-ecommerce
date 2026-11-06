<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SellerPlans\Pages;

use App\Filament\Admin\Resources\SellerPlans\SellerPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSellerPlan extends CreateRecord
{
    protected static string $resource = SellerPlanResource::class;
}
