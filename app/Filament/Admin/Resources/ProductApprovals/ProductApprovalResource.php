<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductApprovals;

use App\Enums\ProductApprovalStatus;
use App\Filament\Admin\Resources\ProductApprovals\Pages\ListProductApprovals;
use App\Filament\Admin\Resources\ProductApprovals\Schemas\ProductReviewDetails;
use App\Filament\Admin\Resources\ProductApprovals\Schemas\RejectionForm;
use App\Filament\Shared\ChangeRequestSummary;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Services\Catalog\ProductApprovalService;
use BackedEnum;
use Carbon\CarbonInterface;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use UnitEnum;

/**
 * New products waiting for a decision, oldest first. Read-only: the only things a reviewer can do here are look,
 * approve and reject, and every decision goes through ProductApprovalService.
 */
class ProductApprovalResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'approvals/products';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?string $navigationParentItem = 'Approvals';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'New products';

    protected static ?string $modelLabel = 'new product';

    protected static ?string $pluralModelLabel = 'new products';

    /**
     * The reasons offered as a starting point in the "not approved" modal.
     *
     * @var list<string>
     */
    public const QUICK_PICKS = [
        'Photos are unclear or missing',
        'Title or description is misleading',
        'Wrong category',
        'Prohibited or restricted item',
        'Price looks wrong',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->isCatalogReviewer() === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The one definition of "waiting", shared by the table and the badge so they always agree.
     *
     * @return Builder<Product>
     */
    public static function queue(): Builder
    {
        return Product::query()->where('approval_status', ProductApprovalStatus::PendingReview->value);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::queue();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::queue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['seller.sellerProfile', 'category', 'salesChannel', 'prices.salesChannel'])
                ->addSelect(['submissions_count' => AuditLog::query()
                    ->selectRaw('count(*)')
                    ->where('module', 'approvals')
                    ->where('action', 'product_submitted')
                    ->where('auditable_type', Product::class)
                    ->whereColumn('auditable_id', 'products.id'),
                ]))
            ->defaultSort('submitted_at', 'asc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap()->limit(60),
                TextColumn::make('seller.sellerProfile.store_name')->label('Store')->searchable(),
                TextColumn::make('category.name')->label('Category')->placeholder('—'),
                TextColumn::make('salesChannel.name')->label('Channel')->placeholder('—'),
                TextColumn::make('retail_price')->label('Retail price')
                    ->state(fn (Product $record): string => self::fromPrice($record, SalesChannel::RETAIL)),
                TextColumn::make('wholesale_price')->label('Wholesale from')
                    ->state(fn (Product $record): string => self::fromPrice($record, SalesChannel::WHOLESALE)),
                TextColumn::make('submitted_at')->label('Waiting for')
                    ->state(fn (Product $record): ?string => $record->submitted_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE))
                    ->sortable(),
                TextColumn::make('submissions_count')->label('Submissions')->numeric(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Review')
                    ->slideOver()
                    ->modalHeading(fn (Product $record): string => $record->title)
                    ->modalSubmitAction(false)
                    ->schema(fn () => [self::reviewDetailsEntry()])
                    ->extraModalFooterActions(fn (): array => [
                        self::approveAction()->cancelParentActions(),
                        self::rejectAction()->cancelParentActions(),
                    ]),
                self::approveAction(),
                self::rejectAction(),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')
                    ->label('Approve selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize('review-product')
                    ->requiresConfirmation()
                    ->modalHeading('Approve the selected products?')
                    ->modalDescription(fn (Collection $records): string => 'These will go live: '.$records->pluck('title')->implode(', '))
                    ->deselectRecordsAfterCompletion()
                    ->fillForm(fn (Collection $records): array => ['versions' => $records->mapWithKeys(
                        fn (Product $record): array => [$record->getKey() => app(ProductApprovalService::class)->versionOf($record->fresh())]
                    )->all()])
                    ->schema([Hidden::make('versions')])
                    ->action(function (array $data): void {
                        $service = app(ProductApprovalService::class);
                        $approved = 0;
                        $skipped = 0;

                        foreach ((array) ($data['versions'] ?? []) as $id => $version) {
                            try {
                                $service->approveProduct(Product::query()->findOrFail($id), auth()->user(), (string) $version);
                                $approved++;
                            } catch (DomainException|ModelNotFoundException) {
                                $skipped++;
                            }
                        }

                        $notification = Notification::make()->title("{$approved} approved".($skipped > 0 ? ", {$skipped} skipped (changed since)" : ''));
                        ($skipped > 0 ? $notification->warning() : $notification->success())->send();
                    }),
            ]);
    }

    /**
     * Approve, showing the product exactly as it is when the modal opens; the version is captured at that same mount,
     * so a seller edit after this point makes the approval stale and it is refused.
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->authorize('review-product')
            ->modalHeading('Approve this product?')
            ->modalDescription('Buyers will be able to see it in the shop, and the seller is told.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Approve')
            ->fillForm(fn (?Product $record): array => ['version' => self::versionFor($record)])
            ->schema(fn (): array => [self::reviewDetailsEntry(), Hidden::make('version')])
            ->action(function (?Product $record, array $data): void {
                self::decide($record, fn () => app(ProductApprovalService::class)->approveProduct($record, auth()->user(), (string) $data['version']), 'Product approved');
            });
    }

    /**
     * Not approve, with the same review content as the approve modal and the version captured when it opens.
     */
    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Not approve')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize('review-product')
            ->modalHeading('Why is this product not approved?')
            ->modalWidth(Width::ThreeExtraLarge)
            ->fillForm(fn (?Product $record): array => ['version' => self::versionFor($record)])
            ->schema(fn (): array => [self::reviewDetailsEntry(), ...RejectionForm::fields(self::QUICK_PICKS), Hidden::make('version')])
            ->action(function (?Product $record, array $data): void {
                self::decide($record, fn () => app(ProductApprovalService::class)->rejectProduct($record, auth()->user(), (string) $data['reason'], (string) $data['version']), 'Product not approved');
            });
    }

    /**
     * The product as buyers would see it, plus the seller's standing; shared by the Review slide-over and both decisions.
     */
    protected static function reviewDetailsEntry(): TextEntry
    {
        return TextEntry::make('details')->hiddenLabel()->html()
            ->state(fn (?Product $record) => $record === null ? null : ProductReviewDetails::html($record));
    }

    protected static function versionFor(?Product $record): string
    {
        return $record === null ? '' : app(ProductApprovalService::class)->versionOf($record->fresh());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductApprovals::route('/'),
        ];
    }

    /**
     * Run a decision; a refusal (changed since, already decided, bad reason) becomes a plain-language notification.
     */
    public static function decide(?Model $record, callable $decision, string $successTitle): void
    {
        if ($record === null) {
            Notification::make()->title('Could not complete this')
                ->body('This was already decided, withdrawn or replaced by a newer version while you were looking at it. The list has been refreshed.')
                ->danger()->send();

            return;
        }

        try {
            $decision();
        } catch (DomainException $e) {
            Notification::make()->title('Could not complete this')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    /**
     * Lowest price a channel offers on the product, as "from ৳x", or a dash.
     */
    protected static function fromPrice(Product $product, string $channelSlug): string
    {
        $price = $product->prices->filter(fn ($row) => $row->salesChannel?->slug === $channelSlug)->min('unit_price');

        return $price === null ? '—' : ChangeRequestSummary::money($price);
    }
}
