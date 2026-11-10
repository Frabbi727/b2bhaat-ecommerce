<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Tables\Columns\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * UX-6: on a phone, seller tables show at most four columns; the rest appear from the md breakpoint up.
 */
class SellerTablesOnPhonesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function tables(): array
    {
        $base = 'app/Filament/Seller/';

        return [
            'wholesale quotes' => [$base.'Resources/RfqRequests/Tables/RfqRequestsTable.php', ['created_at', 'product.title', 'target_quantity', 'status']],
            'flash sale submissions' => [$base.'Resources/FlashSales/RelationManagers/MySubmissionsRelationManager.php', ['product.title', 'campaign_price', 'status']],
            'reviews' => [$base.'Resources/ProductReviews/Tables/ProductReviewsTable.php', ['product.title', 'rating', 'comment']],
            'returns' => [$base.'Resources/ReturnRequests/Tables/ReturnRequestsTable.php', ['created_at', 'subOrder.sub_order_number', 'requested_action', 'status']],
            'flash sales' => [$base.'Resources/FlashSales/FlashSaleResource.php', ['name', 'registration_status', 'starts_at']],
            'recent orders' => [$base.'Widgets/SellerRecentOrdersWidget.php', ['sub_order_number', 'fulfillmentStatus.name', 'net_earnings']],
            'account statement' => [$base.'Resources/WalletTransactions/Tables/WalletTransactionsTable.php', ['created_at', 'transaction_type', 'amount']],
            'price change requests' => [$base.'Resources/Products/RelationManagers/ChangeRequestsRelationManager.php', ['status', 'summary', 'created_at']],
            'activity log' => [$base.'Resources/Activities/SellerActivityResource.php', ['created_at', 'action', 'description']],
        ];
    }

    /**
     * @param  list<string>  $phoneColumns
     */
    #[DataProvider('tables')]
    public function test_only_key_columns_show_on_a_phone(string $file, array $phoneColumns): void
    {
        $code = (string) file_get_contents(base_path($file));
        preg_match_all("/(?:TextColumn|IconColumn|ToggleColumn|ImageColumn)::make\\('([^']+)'\\)(.*?)(?=(?:TextColumn|IconColumn|ToggleColumn|ImageColumn)::make\\(|\\]\\)\\s*->|\\z)/s", $code, $matches, PREG_SET_ORDER);

        $shownOnPhone = [];
        foreach ($matches as [, $name, $chain]) {
            if (! str_contains($chain, "->visibleFrom('md')")) {
                $shownOnPhone[] = $name;
            }
        }

        $this->assertSame($phoneColumns, $shownOnPhone);
        $this->assertTrue(method_exists(Column::class, 'visibleFrom'));
    }
}
