<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductApprovals\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The "not approved" modal: a quick-pick list that pre-fills a reason the reviewer can edit.
 * The reason is trimmed, then must be 10 to 1000 characters (the service checks it again).
 */
class RejectionForm
{
    /**
     * @param  list<string>  $quickPicks
     * @return array<int, mixed>
     */
    public static function fields(array $quickPicks): array
    {
        return [
            Select::make('quick_pick')
                ->label('Common reasons')
                ->options(array_combine($quickPicks, $quickPicks))
                ->placeholder('Pick one to start from')
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(fn (Set $set, ?string $state) => filled($state) ? $set('reason', $state.'. ') : null),
            Textarea::make('reason')
                ->label('Reason (the seller sees this)')
                ->required()
                ->trim()
                ->minLength(10)
                ->maxLength(1000)
                ->rows(4),
        ];
    }
}
