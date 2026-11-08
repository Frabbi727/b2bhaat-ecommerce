<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\SalesChannel;

/**
 * A product's complete price list in one canonical shape, so two lists can be compared reliably
 * (formatting and row order never count as a change) and checked against the pricing rules.
 */
final readonly class PriceSet
{
    public const MAX_ROWS = 200;

    /**
     * @param  list<array{sales_channel_id: int, variation_id: ?int, min_quantity: int, max_quantity: ?int, unit_price: string}>  $rows
     */
    private function __construct(
        private array $rows,
        private ?string $regularPrice,
    ) {}

    /**
     * @param  array<int|string, mixed>  $rows
     */
    public static function fromInput(array $rows, int|float|string|null $regularPrice): self
    {
        $normalised = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $variation = $row['variation_id'] ?? null;
            $max = $row['max_quantity'] ?? null;

            $normalised[] = [
                'sales_channel_id' => (int) ($row['sales_channel_id'] ?? 0),
                'variation_id' => ($variation === null || $variation === '') ? null : (int) $variation,
                'min_quantity' => (int) ($row['min_quantity'] ?? 0),
                'max_quantity' => ($max === null || $max === '') ? null : (int) $max,
                'unit_price' => self::money($row['unit_price'] ?? 0),
            ];
        }

        usort($normalised, fn (array $a, array $b): int => [
            $a['sales_channel_id'],
            $a['variation_id'] ?? 0,
            $a['variation_id'] === null ? 0 : 1,
            $a['min_quantity'],
            $a['max_quantity'] ?? PHP_INT_MAX,
            $a['unit_price'],
        ] <=> [
            $b['sales_channel_id'],
            $b['variation_id'] ?? 0,
            $b['variation_id'] === null ? 0 : 1,
            $b['min_quantity'],
            $b['max_quantity'] ?? PHP_INT_MAX,
            $b['unit_price'],
        ]);

        return new self(
            $normalised,
            ($regularPrice === null || $regularPrice === '') ? null : self::money($regularPrice),
        );
    }

    public static function fromProduct(Product $product): self
    {
        $rows = $product->prices()->get()->map(fn ($price): array => [
            'sales_channel_id' => $price->sales_channel_id,
            'variation_id' => $price->variation_id,
            'min_quantity' => $price->min_quantity,
            'max_quantity' => $price->max_quantity,
            'unit_price' => $price->unit_price,
        ])->all();

        return self::fromInput($rows, $product->regular_price);
    }

    /**
     * @return list<array{sales_channel_id: int, variation_id: ?int, min_quantity: int, max_quantity: ?int, unit_price: string}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function regularPrice(): ?string
    {
        return $this->regularPrice;
    }

    public function fingerprint(int $salesChannelId, int $categoryId): string
    {
        return hash('sha256', json_encode([$this->rows, $this->regularPrice, $salesChannelId, $categoryId], JSON_THROW_ON_ERROR));
    }

    public function equals(self $other): bool
    {
        return $this->rows === $other->rows && $this->regularPrice === $other->regularPrice;
    }

    /**
     * Plain-language problems with this price list for a product sold in $productChannel; empty when valid.
     *
     * @return list<string>
     */
    public function validate(SalesChannel $productChannel): array
    {
        $errors = [];

        if (count($this->rows) > self::MAX_ROWS) {
            $errors[] = 'A product can have at most 200 price rows.';
        }

        if (collect($this->rows)->contains(fn (array $row): bool => (float) $row['unit_price'] < 0.01)) {
            $errors[] = 'Every price must be at least ৳0.01.';
        }

        if (collect($this->rows)->contains(fn (array $row): bool => $row['min_quantity'] < 1)) {
            $errors[] = 'Minimum quantity must be 1 or more.';
        }

        if (collect($this->rows)->contains(fn (array $row): bool => $row['max_quantity'] !== null && $row['max_quantity'] < $row['min_quantity'])) {
            $errors[] = "Maximum quantity can't be below the minimum.";
        }

        array_push($errors, ...$this->overlapErrors());

        $slugs = SalesChannel::pluck('slug', 'id');
        $rowChannels = collect($this->rows)->map(fn (array $row): ?string => $slugs[$row['sales_channel_id']] ?? null);

        if (in_array($productChannel->slug, [SalesChannel::RETAIL, SalesChannel::BOTH], true) && ! $rowChannels->contains(SalesChannel::RETAIL)) {
            $errors[] = 'Add a Retail price: shoppers need a price to buy this product.';
        }

        if (in_array($productChannel->slug, [SalesChannel::WHOLESALE, SalesChannel::BOTH], true) && ! $rowChannels->contains(SalesChannel::WHOLESALE)) {
            $errors[] = 'Add at least one Wholesale price tier for business buyers.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function overlapErrors(): array
    {
        $errors = [];
        $groups = [];

        foreach ($this->rows as $row) {
            if ($row['min_quantity'] < 1 || ($row['max_quantity'] !== null && $row['max_quantity'] < $row['min_quantity'])) {
                continue;
            }

            $groups[$row['sales_channel_id'].':'.($row['variation_id'] ?? 'null')][] = $row;
        }

        foreach ($groups as $group) {
            $count = count($group);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $group[$i];
                    $b = $group[$j];

                    $aEnd = $a['max_quantity'] ?? PHP_INT_MAX;
                    $bEnd = $b['max_quantity'] ?? PHP_INT_MAX;

                    if ($a['min_quantity'] <= $bEnd && $b['min_quantity'] <= $aEnd) {
                        $errors[] = sprintf(
                            'Price tiers %s and %s overlap. Each quantity must fall in one tier only.',
                            self::range($a),
                            self::range($b),
                        );
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array{min_quantity: int, max_quantity: ?int}  $row
     */
    private static function range(array $row): string
    {
        return $row['max_quantity'] === null ? $row['min_quantity'].'+' : $row['min_quantity'].'–'.$row['max_quantity'];
    }

    private static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
