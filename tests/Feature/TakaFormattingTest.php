<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use App\Services\Formatting\Money;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX-1: money on screen always reads ৳1,234.50, never "BDT 1,234.50" or "Tk".
 */
class TakaFormattingTest extends TestCase
{
    use RefreshDatabase;

    public function test_taka_formatter(): void
    {
        $this->assertSame('৳1,234.50', Money::taka(1234.5));
        $this->assertSame('৳0.00', Money::taka('0'));
        $this->assertSame('-৳75.25', Money::taka(-75.25));
        $this->assertSame('—', Money::taka(null));
    }

    public function test_admin_order_list_shows_taka_not_bdt(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->actingAs(User::where('email', 'admin@b2bhaat.com')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $order = Order::firstOrFail();

        Livewire::test(ListOrders::class)
            ->assertSee(Money::taka($order->total_amount))
            ->assertDontSee('BDT '.number_format((float) $order->total_amount, 2));
    }

    public function test_no_screen_uses_the_bdt_money_format_or_tk_labels(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament')));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            if (str_contains($code, "->money('BDT')") || preg_match('/\(Tk\b/', $code)) {
                $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders);
    }
}
