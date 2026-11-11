<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductModeration\Pages;

use App\Enums\ProductApprovalStatus;
use App\Filament\Admin\Resources\ProductModeration\ProductModerationResource;
use App\Models\Product;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListModeratedProducts extends ListRecords
{
    protected static string $resource = ProductModerationResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Every seller\'s products in one place. Hide a product to take it out of the shop; the seller sees your reason and cannot switch it back on.';
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $waiting = Product::where('approval_status', ProductApprovalStatus::PendingReview->value)->count();
        $hidden = Product::whereNotNull('blocked_at')->count();

        return [
            'all' => Tab::make('All products'),
            'live' => Tab::make('Live')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNull('blocked_at')
                    ->where('is_active', true)
                    ->where('approval_status', ProductApprovalStatus::Approved->value)),
            'waiting' => Tab::make('Waiting for review')
                ->icon('heroicon-m-clock')
                ->badge($waiting ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', ProductApprovalStatus::PendingReview->value)),
            'featured' => Tab::make('Featured')
                ->icon('heroicon-m-star')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_featured', true)),
            'hidden' => Tab::make('Hidden by admin')
                ->icon('heroicon-m-eye-slash')
                ->badge($hidden ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('blocked_at')),
        ];
    }
}
