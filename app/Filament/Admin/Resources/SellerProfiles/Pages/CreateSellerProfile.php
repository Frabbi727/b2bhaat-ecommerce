<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Pages;

use App\Filament\Admin\Resources\SellerProfiles\SellerProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSellerProfile extends CreateRecord
{
    protected static string $resource = SellerProfileResource::class;
}
