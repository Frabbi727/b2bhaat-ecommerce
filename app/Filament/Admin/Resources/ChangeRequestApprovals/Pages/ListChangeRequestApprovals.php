<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ChangeRequestApprovals\Pages;

use App\Filament\Admin\Concerns\ResolvesRecordsDecidedMeanwhile;
use App\Filament\Admin\Resources\ChangeRequestApprovals\ChangeRequestApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListChangeRequestApprovals extends ListRecords
{
    use ResolvesRecordsDecidedMeanwhile;

    protected static string $resource = ChangeRequestApprovalResource::class;
}
