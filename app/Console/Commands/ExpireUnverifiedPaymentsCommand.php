<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentStatus;
use App\Models\User;
use App\Notifications\Payments\PaymentsAwaitingVerification;
use App\Services\Payments\PaymentVerificationService;
use App\Services\Settings\StoreConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * PAY-2: reminds admins about bKash/Nagad payments nobody has checked, and cancels the ones left unchecked past
 * the time limit as "payment not confirmed". Both times are store settings.
 */
class ExpireUnverifiedPaymentsCommand extends Command
{
    protected $signature = 'payments:expire-unverified';

    protected $description = 'Remind admins about unchecked bKash/Nagad payments and cancel the ones past the time limit';

    public function handle(StoreConfigurationService $config, PaymentVerificationService $verification): int
    {
        $awaitingId = PaymentStatus::where('slug', 'awaiting_verification')->value('id');
        if ($awaitingId === null) {
            $this->error('The "awaiting verification" payment status is missing; nothing was done.');

            return self::FAILURE;
        }

        $reminderHours = $config->getMfsVerificationReminderHours();
        $timeoutHours = max($config->getMfsVerificationTimeoutHours(), $reminderHours + 1);

        $expired = 0;
        $failed = [];

        // Paged by id, not offset: each expiry takes the order out of the result, which would make offset pages skip
        // orders (Wave 1 review M-2). One failing order is reported and the rest still expire (M-1).
        Order::where('payment_status_id', $awaitingId)
            ->where('created_at', '<=', now()->subHours($timeoutHours))
            ->lazyById(200)
            ->each(function (Order $order) use ($verification, $timeoutHours, &$expired, &$failed): void {
                try {
                    if ($verification->expireUnverified($order, $timeoutHours)) {
                        $expired++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failed[] = $order->order_number;
                }
            });

        $toRemind = Order::where('payment_status_id', $awaitingId)
            ->where('created_at', '<=', now()->subHours($reminderHours))
            ->whereNull('verification_reminded_at')
            ->orderBy('id')
            ->get(['id', 'order_number']);

        if ($toRemind->isNotEmpty()) {
            $admins = User::query()
                ->whereHas('role', fn ($query) => $query->whereIn('slug', ['super_admin', 'admin']))
                ->where('is_active', true)
                ->get();

            Notification::send($admins, new PaymentsAwaitingVerification($toRemind->pluck('order_number')->all(), $reminderHours, $timeoutHours));
            Order::whereKey($toRemind->pluck('id'))->update(['verification_reminded_at' => now()]);
        }

        $this->info("{$toRemind->count()} payment(s) reminded, {$expired} order(s) cancelled as payment not confirmed.");

        if ($failed !== []) {
            $this->error(count($failed).' order(s) could not be cancelled and will be tried again next run: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
