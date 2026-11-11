<?php

declare(strict_types=1);

namespace Tests\Feature\SellerAccounting\Concerns;

use App\Models\Accounting\AccountingFiscalYear;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Category;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerAccounting\SellerAccount;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\Unit;
use App\Models\User;
use App\Services\SellerAccounting\SellerFinancialReportService;
use Database\Seeders\AccountingChartOfAccountsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Support\Str;

/**
 * Sellers, products and orders built by hand (orders and sub-orders have no factories), plus the platform ledger
 * setup the escrow service needs.
 */
trait BuildsSellerBooksFixtures
{
    protected function seedSellerBooksPrerequisites(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(AccountingChartOfAccountsSeeder::class);

        $year = AccountingFiscalYear::firstOrCreate(
            ['name' => 'FY '.now()->year],
            ['start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(), 'is_closed' => false]
        );

        AccountingPeriod::firstOrCreate(
            ['fiscal_year_id' => $year->id, 'period_number' => (int) now()->format('n')],
            ['name' => now()->format('F Y'), 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'is_closed' => false]
        );
    }

    protected function makeSeller(bool $approved = true): User
    {
        $seller = User::factory()->create([
            'role_id' => Role::where('slug', 'seller')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $slug = 'books-store-'.Str::lower(Str::random(6));
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Books Store '.$seller->id,
            'store_slug' => $slug,
            'is_approved' => $approved,
        ]);

        return $seller;
    }

    protected function makeBuyer(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'retail_buyer')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    protected function makeProduct(User $seller, string $title, float $buyPrice): Product
    {
        $category = Category::firstOrCreate(['slug' => 'books-test'], ['name' => 'Books Test', 'is_active' => true]);

        return Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'sales_channel_id' => SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail()->id,
            'unit_id' => Unit::where('symbol', 'pc')->firstOrFail()->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'buy_price' => $buyPrice,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array{product: Product, quantity: int, unit_price: float, unit_cost?: float|null}>  $items
     */
    protected function makeSubOrder(
        User $seller,
        array $items,
        float $commission,
        float $shipping = 0.0,
        bool $selfDelivered = false,
        string $paymentMethod = 'bkash',
        string $paymentStatus = 'paid',
        string $fulfillment = 'delivered',
    ): SubOrder {
        $subtotal = round(array_sum(array_map(fn (array $item): float => $item['unit_price'] * $item['quantity'], $items)), 2);
        $number = 'ORD-BOOKS-'.Str::upper(Str::random(6));

        $order = Order::create([
            'order_number' => $number,
            'customer_id' => $this->makeBuyer()->id,
            'order_status_id' => OrderStatus::where('slug', 'processing')->firstOrFail()->id,
            'payment_status_id' => PaymentStatus::where('slug', $paymentStatus)->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', $paymentMethod)->firstOrFail()->id,
            'shipping_address' => ['address' => 'Dhaka'],
            'total_amount' => $subtotal + $shipping,
            'shipping_amount' => $shipping,
            'discount_amount' => 0,
        ]);

        $subOrder = SubOrder::create([
            'parent_order_id' => $order->id,
            'seller_id' => $seller->id,
            'sub_order_number' => $number.'-A',
            'fulfillment_status_id' => FulfillmentStatus::where('slug', $fulfillment)->firstOrFail()->id,
            'subtotal' => $subtotal,
            'commission_amount' => $commission,
            'shipping_amount' => $shipping,
            'is_self_delivered' => $selfDelivered,
        ]);

        foreach ($items as $item) {
            OrderItem::create([
                'sub_order_id' => $subOrder->id,
                'product_id' => $item['product']->id,
                'sales_channel_id' => $item['product']->sales_channel_id,
                'unit_price' => $item['unit_price'],
                'unit_cost' => array_key_exists('unit_cost', $item) ? $item['unit_cost'] : null,
                'quantity' => $item['quantity'],
                'total_price' => round($item['unit_price'] * $item['quantity'], 2),
                'commission_rate' => 10,
            ]);
        }

        return $subOrder->fresh();
    }

    /**
     * An approved return of some items, priced the way PartialReturnService prices it.
     *
     * @param  list<array{item: OrderItem, quantity: int, commission: float}>  $lines
     */
    protected function makeReturn(SubOrder $subOrder, array $lines): ReturnRequest
    {
        $returnRequest = ReturnRequest::create([
            'sub_order_id' => $subOrder->id,
            'customer_id' => $subOrder->order->customer_id,
            'reason' => 'damaged',
            'requested_action' => 'refund',
            'description' => 'Item arrived damaged',
            'status' => 'approved',
        ]);

        foreach ($lines as $line) {
            $goods = round((float) $line['item']->unit_price * $line['quantity'], 2);
            ReturnRequestItem::create([
                'return_request_id' => $returnRequest->id,
                'order_item_id' => $line['item']->id,
                'quantity' => $line['quantity'],
                'refund_amount' => $goods,
                'commission_amount' => $line['commission'],
                'seller_amount' => round($goods - $line['commission'], 2),
                'discount_share' => 0,
            ]);
        }

        return $returnRequest;
    }

    /** Balance of one of the seller's accounts on its debit side (credit balances are negative). */
    protected function debitBalance(User $seller, string $slug): float
    {
        $account = SellerAccount::where('seller_id', $seller->id)->where('slug', $slug)->firstOrFail();
        $row = app(SellerFinancialReportService::class)->accountBalances($seller->id, null, null)[$account->id];

        return round($row['debit'] - $row['credit'], 2);
    }

    protected function assertBooksMatchWallet(User $seller): void
    {
        $check = app(SellerFinancialReportService::class)->walletReconciliation($seller->id);

        $this->assertTrue($check['is_consistent'], 'Seller books differ from the wallet: '.json_encode($check));
    }

    protected function assertTrialBalanceBalances(User $seller): void
    {
        $trial = app(SellerFinancialReportService::class)->trialBalance($seller->id, null);

        $this->assertTrue($trial['is_balanced'], 'Trial balance does not balance: '.json_encode($trial));
    }
}
