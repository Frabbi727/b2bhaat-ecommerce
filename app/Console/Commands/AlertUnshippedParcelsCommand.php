<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SubOrder;
use App\Models\User;
use App\Notifications\Orders\ParcelsAwaitingDispatch;
use App\Services\Orders\ParcelCancellationService;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\OrderNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * AR-16: parcels that could ship (cash on delivery, or paid) but haven't after the set number of days. The seller is
 * reminded by SMS and admins get one bell entry. Each parcel is flagged once; nothing is cancelled.
 */
class AlertUnshippedParcelsCommand extends Command
{
    protected $signature = 'orders:alert-unshipped-parcels';

    protected $description = 'Remind sellers and admins about COD and paid parcels not shipped after the set number of days';

    public function handle(StoreConfigurationService $config, OrderNotificationService $notifications): int
    {
        $days = $config->getUnshippedParcelAlertDays();

        $parcels = SubOrder::query()
            ->whereNull('unshipped_alerted_at')
            ->where('created_at', '<=', now()->subDays($days))
            ->whereHas('fulfillmentStatus', fn (Builder $query) => $query->whereIn('slug', ParcelCancellationService::NOT_YET_DISPATCHED))
            ->whereHas('order', fn (Builder $order) => $order
                ->whereHas('paymentMethod', fn (Builder $method) => $method->where('slug', 'cod'))
                ->orWhereHas('paymentStatus', fn (Builder $status) => $status->where('slug', 'paid')))
            ->with('seller')
            ->orderBy('id')
            ->get();

        if ($parcels->isEmpty()) {
            $this->info('No parcels waiting to ship.');

            return self::SUCCESS;
        }

        foreach ($parcels as $parcel) {
            try {
                $notifications->sendUnshippedParcelReminder($parcel, $days);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $admins = User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['super_admin', 'admin']))
            ->where('is_active', true)
            ->get();
        Notification::send($admins, new ParcelsAwaitingDispatch($parcels->pluck('sub_order_number')->all(), $days));

        SubOrder::whereKey($parcels->pluck('id'))->update(['unshipped_alerted_at' => now()]);

        $this->info("{$parcels->count()} parcel(s) not shipped after {$days} days: sellers and admins told.");

        return self::SUCCESS;
    }
}
