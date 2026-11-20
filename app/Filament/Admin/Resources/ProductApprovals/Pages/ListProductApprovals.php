<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductApprovals\Pages;

use App\Filament\Admin\Concerns\ResolvesRecordsDecidedMeanwhile;
use App\Filament\Admin\Resources\ProductApprovals\ProductApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListProductApprovals extends ListRecords
{
    use ResolvesRecordsDecidedMeanwhile;

    protected static string $resource = ProductApprovalResource::class;
}
