<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\Products\Actions;

use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Inventory\StockAdjustmentService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * INV-3: "Adjust stock" adds or removes units from the live stock. Stock is not editable in the product form, so
 * saving the form can never put back units that orders reserved while it was open.
 */
class AdjustStockAction
{
    public static function make(): Action
    {
        return Action::make('adjustStock')
            ->label('Adjust stock')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->modalHeading(fn (Product $record): string => "Adjust stock · {$record->title}")
            ->modalDescription('Add units you received, or remove units that were damaged, lost or sold elsewhere. The change applies to the stock as it is right now, including any orders placed meanwhile.')
            ->modalSubmitActionLabel('Save adjustment')
            ->schema([
                Select::make('variation_id')
                    ->label('Option')
                    ->options(fn (Product $record): array => self::variationOptions($record))
                    ->default(fn (Product $record): ?int => count(self::variationOptions($record)) === 1 ? array_key_first(self::variationOptions($record)) : null)
                    ->visible(fn (Product $record): bool => (bool) $record->has_variations)
                    ->required(fn (Product $record): bool => (bool) $record->has_variations)
                    ->native(false)
                    ->live(),

                TextInput::make('change')
                    ->label('Units to add or remove')
                    ->helperText(fn (Get $get, Product $record): string => 'Use a minus sign to remove, e.g. -3. In stock now: '.(self::variationFor($record, $get('variation_id'))?->stock_quantity ?? 0).'.')
                    ->integer()
                    ->required()
                    ->notIn(['0'])
                    ->validationMessages(['not_in' => 'Enter how many units to add or remove.']),

                Select::make('reason')
                    ->label('Reason')
                    ->options(StockAdjustmentService::REASONS)
                    ->required()
                    ->native(false),

                Textarea::make('note')
                    ->label('Note (optional)')
                    ->placeholder('e.g. Delivery from the factory, invoice 4512')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data, Product $record, Action $action, mixed $livewire): void {
                $variation = self::variationFor($record, $data['variation_id'] ?? null);

                if (! $variation) {
                    Notification::make()->danger()->title('Choose which option to adjust')->send();
                    $action->halt();
                }

                try {
                    $stock = app(StockAdjustmentService::class)->adjust($variation, (int) $data['change'], (string) $data['reason'], $data['note'] ?? null);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('Stock not changed')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                if ($livewire instanceof EditProduct) {
                    $livewire->showAdjustedStock($variation->id, $stock);
                }

                Notification::make()->success()->title('Stock updated')->body("{$variation->sku} now has {$stock} in stock.")->send();
            });
    }

    /**
     * @return array<int, string>
     */
    public static function variationOptions(Product $product): array
    {
        return $product->variations()->with('attributeValues.attribute')->orderBy('id')->get()
            ->mapWithKeys(function (ProductVariation $variation): array {
                $options = $variation->attributeValues->map(fn ($value): string => "{$value->attribute?->name}: {$value->value}")->implode(', ');

                return [$variation->id => trim("{$variation->sku} ".($options !== '' ? "· {$options} " : '')."({$variation->stock_quantity} in stock)")];
            })
            ->all();
    }

    /**
     * The chosen option of this product only; a simple product uses its default variation.
     */
    private static function variationFor(Product $product, mixed $variationId): ?ProductVariation
    {
        if (! $product->has_variations) {
            return $product->ensureDefaultVariation();
        }

        return filled($variationId) ? $product->variations()->whereKey((int) $variationId)->first() : null;
    }
}
