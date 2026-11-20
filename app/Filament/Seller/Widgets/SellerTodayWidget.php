<?php

declare(strict_types=1);

namespace App\Filament\Seller\Widgets;

use App\Filament\Seller\Pages\StoreSettings;
use App\Filament\Seller\Pages\SubscriptionBilling;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Filament\Seller\Resources\ReturnRequests\ReturnRequestResource;
use App\Filament\Seller\Resources\RfqRequests\RfqRequestResource;
use App\Filament\Seller\Resources\SellerWallets\SellerWalletResource;
use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\RfqRequest;
use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Services\Formatting\Money;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The top of the seller's dashboard: what needs them today, any warning about the store, and their money, in
 * one glance on a phone. Each to-do links to the list that holds the work.
 */
class SellerTodayWidget extends Widget
{
    /** PERF-2: refresh once a minute rather than Filament's default of every 5 seconds. */
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.seller.widgets.today';

    protected function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    /**
     * @return array{
     *     alerts: list<array{tone: string, icon: string, title: string, body: string, url: ?string, link: ?string}>,
     *     todos: list<array{count: int, label: string, hint: string, icon: string, tone: string, url: string}>,
     *     money: list<array{label: string, value: string, hint: string, url: ?string}>,
     * }
     */
    protected function getViewData(): array
    {
        $sellerId = (int) auth()->id();

        return [
            'alerts' => $this->alerts($sellerId),
            'todos' => $this->todos($sellerId),
            'money' => $this->money($sellerId),
        ];
    }

    /**
     * @return list<array{count: int, label: string, hint: string, icon: string, tone: string, url: string}>
     */
    protected function todos(int $sellerId): array
    {
        $parcels = SubOrder::query()->where('seller_id', $sellerId);
        $paidOrCod = fn (Builder $order): Builder => $order->where(fn (Builder $q): Builder => $q
            ->whereHas('paymentMethod', fn (Builder $m): Builder => $m->where('slug', 'cod'))
            ->orWhereHas('paymentStatus', fn (Builder $s): Builder => $s->whereIn('slug', PaymentStatus::MONEY_RECEIVED)));
        $inStatus = fn (array $slugs): \Closure => fn (Builder $status): Builder => $status->whereIn('slug', $slugs);

        $toPack = (clone $parcels)->whereHas('fulfillmentStatus', $inStatus(['unfulfilled']))->whereHas('order', $paidOrCod);
        $toPackCount = (clone $toPack)->count();
        $oldestToPack = $toPackCount > 0 ? (clone $toPack)->min('created_at') : null;

        $todos = [
            [
                'count' => $toPackCount,
                'label' => str('order')->plural($toPackCount).' to pack',
                'hint' => $oldestToPack ? 'Oldest has waited '.Carbon::parse($oldestToPack)->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE) : '',
                'icon' => 'heroicon-o-archive-box',
                'tone' => $oldestToPack && Carbon::parse($oldestToPack)->lt(now()->subDays(2)) ? 'danger' : 'warning',
                'url' => SubOrderResource::getUrl('index', ['activeTab' => 'to_pack']),
            ],
            [
                'count' => (clone $parcels)->whereHas('fulfillmentStatus', $inStatus(['processing']))->count(),
                'label' => 'packed, ready to send',
                'hint' => 'Hand to a courier or deliver yourself',
                'icon' => 'heroicon-o-truck',
                'tone' => 'primary',
                'url' => SubOrderResource::getUrl('index', ['activeTab' => 'ready_to_ship']),
            ],
            [
                'count' => (clone $parcels)->where('is_self_delivered', true)->whereHas('fulfillmentStatus', $inStatus(['shipped', 'in_transit', 'out_for_delivery']))->count(),
                'label' => 'handover codes to collect',
                'hint' => 'Your own deliveries: ask the customer for their code',
                'icon' => 'heroicon-o-key',
                'tone' => 'primary',
                'url' => SubOrderResource::getUrl('index', ['activeTab' => 'on_the_way']),
            ],
            [
                'count' => ReturnRequest::query()->whereHas('subOrder', fn (Builder $q): Builder => $q->where('seller_id', $sellerId))->where('status', ReturnRequest::STATUS_PENDING)->count(),
                'label' => 'returns to answer',
                'hint' => 'Approve or reject',
                'icon' => 'heroicon-o-arrow-uturn-left',
                'tone' => 'warning',
                'url' => ReturnRequestResource::getUrl('index'),
            ],
            [
                'count' => RfqRequest::query()->whereHas('product', fn (Builder $q): Builder => $q->where('seller_id', $sellerId))->where('status', 'pending')->count(),
                'label' => 'wholesale quotes to price',
                'hint' => 'Buyers are waiting for your price',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'tone' => 'primary',
                'url' => RfqRequestResource::getUrl('index'),
            ],
            [
                // Same rule as the Products "Out of stock" tab: no option left with stock
                'count' => Product::query()->where('seller_id', $sellerId)->where('is_active', true)->whereNull('blocked_at')
                    ->whereDoesntHave('variations', fn (Builder $v): Builder => $v->where('stock_quantity', '>', 0))->count(),
                'label' => 'products out of stock',
                'hint' => 'Buyers can\'t order these',
                'icon' => 'heroicon-o-cube',
                'tone' => 'danger',
                'url' => ProductResource::getUrl('index', ['activeTab' => 'out_of_stock']),
            ],
        ];

        return array_values(array_filter($todos, fn (array $todo): bool => $todo['count'] > 0));
    }

    /**
     * @return list<array{tone: string, icon: string, title: string, body: string, url: ?string, link: ?string}>
     */
    protected function alerts(int $sellerId): array
    {
        $alerts = [];

        if (SellerSubscription::query()->where('seller_id', $sellerId)->where('status', SellerSubscription::STATUS_SUSPENDED)->exists()) {
            $alerts[] = ['tone' => 'danger', 'icon' => 'heroicon-o-no-symbol', 'title' => 'Your store is suspended',
                'body' => 'Buyers can\'t order from you until your bills are paid.', 'url' => SubscriptionBilling::getUrl(), 'link' => 'Pay bills'];
        }

        $unpaid = SellerInvoice::query()->where('seller_id', $sellerId)->where('status', SellerInvoice::STATUS_UNPAID);
        $unpaidTotal = (float) (clone $unpaid)->sum('total_amount');
        if ($unpaidTotal > 0) {
            $earliestDue = (clone $unpaid)->min('due_date');
            $dueDate = $earliestDue ? Carbon::parse($earliestDue) : null;
            $overdue = $dueDate?->lt(now(FilamentTimezone::get())->startOfDay()) ?? false;

            $alerts[] = ['tone' => $overdue ? 'danger' : 'warning', 'icon' => 'heroicon-o-document-text',
                'title' => ($overdue ? 'Overdue bill: ' : 'Bill to pay: ').Money::taka($unpaidTotal),
                'body' => $dueDate ? ($overdue ? 'It was due on '.$dueDate->format('d M').'.' : 'Due by '.$dueDate->format('d M').'.') : 'Pay it from Subscription & billing.',
                'url' => SubscriptionBilling::getUrl(), 'link' => 'Pay now'];
        }

        $profile = auth()->user()?->sellerProfile;
        if ($profile?->is_on_vacation) {
            $alerts[] = ['tone' => 'info', 'icon' => 'heroicon-o-sun', 'title' => 'Vacation mode is on',
                'body' => 'Your store shows a vacation notice'.($profile->vacation_ends_at ? ' until '.$profile->vacation_ends_at->format('d M') : '').'.',
                'url' => StoreSettings::getUrl(), 'link' => 'Store settings'];
        }

        return $alerts;
    }

    /**
     * @return list<array{label: string, value: string, hint: string, url: ?string}>
     */
    protected function money(int $sellerId): array
    {
        $wallet = SellerWallet::query()->where('seller_id', $sellerId)->first();
        $startOfToday = now(FilamentTimezone::get())->startOfDay()->setTimezone(config('app.timezone'));

        $today = SubOrder::query()
            ->where('seller_id', $sellerId)
            ->where('created_at', '>=', $startOfToday)
            ->whereDoesntHave('fulfillmentStatus', fn (Builder $q): Builder => $q->where('slug', 'cancelled'))
            ->selectRaw('count(*) as orders, coalesce(sum(subtotal), 0) as total')
            ->first();
        $ordersToday = (int) ($today->orders ?? 0);

        return [
            ['label' => 'Ready to withdraw', 'value' => Money::taka($wallet->available_balance ?? 0), 'hint' => 'Your available balance', 'url' => SellerWalletResource::getUrl('index')],
            ['label' => 'On hold', 'value' => Money::taka($wallet->escrow_held_balance ?? 0), 'hint' => 'Moves to your balance after the buyer\'s check period', 'url' => SellerWalletResource::getUrl('index')],
            ['label' => 'Sold today', 'value' => Money::taka($today->total ?? 0), 'hint' => $ordersToday.' '.str('order')->plural($ordersToday), 'url' => SubOrderResource::getUrl('index')],
        ];
    }
}
