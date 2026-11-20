<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\SellerWallets\SellerWalletResource;
use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Filament\Seller\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\SubOrder;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UX-2: sellers read plain words. "Money on hold after delivery" instead of escrow, "order" instead of sub-order or
 * consignment, "statement" instead of ledger.
 */
class SellerPlainWordingTest extends TestCase
{
    use RefreshDatabase;

    private const JARGON = ['escrow', 'sub-order', 'consignment', 'ledger'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->actingAs(User::where('email', 'seller@nailsfactory.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('seller'));
    }

    private function assertNoJargon(TestResponse $response): void
    {
        $response->assertOk();
        $text = strtolower(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', (string) $response->getContent())));

        $text = (string) preg_replace('/\s+/', ' ', $text);
        $found = [];
        foreach (self::JARGON as $word) {
            $offset = 0;
            while (($at = strpos($text, $word, $offset)) !== false) {
                $found[] = '…'.substr($text, max(0, $at - 50), 100 + strlen($word)).'…';
                $offset = $at + 1;
            }
        }

        $this->assertSame([], $found, 'Seller page shows technical words');
    }

    public function test_dashboard_statement_and_wallet_use_plain_words(): void
    {
        $this->assertNoJargon($this->get(Filament::getPanel('seller')->getUrl()));
        $this->assertNoJargon($this->get(WalletTransactionResource::getUrl('index')));
        $this->assertNoJargon($this->get(SellerWalletResource::getUrl('index')));
        $this->assertNoJargon($this->get(SubOrderResource::getUrl('index')));
        $this->assertNoJargon($this->get(route('seller.wallet.statement.print')));
    }

    public function test_printed_label_and_challan_use_plain_words(): void
    {
        $subOrder = SubOrder::firstOrFail();

        $this->assertNoJargon($this->get(route('seller.sub_order.label', $subOrder->sub_order_number)));
        $this->assertNoJargon($this->get(route('seller.sub_order.challan', $subOrder->sub_order_number)));
    }
}
