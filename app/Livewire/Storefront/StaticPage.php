<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\PaymentMethod;
use App\Services\Logistics\ShippingCalculatorService;
use App\Services\Settings\StoreConfigurationService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Policy and information pages (Terms, Privacy, Returns, About, Contact).
 *
 * Every number on these pages (return window, cancellation window, delivery times, payment methods, contact details)
 * is read from the admin store settings, so the policy always matches how the shop actually behaves.
 */
#[Layout('layouts.storefront')]
class StaticPage extends Component
{
    /** @var array<string, string> page key => title */
    public const PAGES = [
        'terms' => 'Terms & Conditions',
        'privacy' => 'Privacy Policy',
        'returns' => 'Returns & Refunds',
        'about' => 'About Us',
        'contact' => 'Contact Us',
    ];

    public string $page = 'about';

    public function mount(string $page): void
    {
        abort_unless(array_key_exists($page, self::PAGES), 404);

        $this->page = $page;
    }

    public function render(): View
    {
        $config = app(StoreConfigurationService::class);
        $identity = $config->getStoreIdentity();

        $deliveryTimes = [];
        foreach (ShippingCalculatorService::ZONE_LABELS as $zone => $label) {
            $deliveryTimes[$label] = $config->getDeliverySla($zone);
        }

        return view('livewire.storefront.static-page', [
            'title' => self::PAGES[$this->page],
            'identity' => $identity,
            'returnWindowHours' => $config->getEscrowHoldingPeriodHours(),
            'cancellationWindowHours' => $config->getOrderCancellationWindowHours(),
            'deliveryTimes' => $deliveryTimes,
            'paymentMethodNames' => PaymentMethod::availableToBuyers()
                ->where('slug', '!=', PaymentMethod::MOCK)
                ->pluck('name')
                ->all(),
        ])->title(self::PAGES[$this->page].' - '.$identity['name']);
    }
}
