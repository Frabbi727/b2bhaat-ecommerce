<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Cart\CartSessionService;
use App\Services\Formatting\Money;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * PAY-5: line totals were added as floats, so a cart worth exactly ৳1.00 (0.70 + 3 × 0.10) added up to
 * 0.9999999999999999 and fell short of a ৳1.00 minimum. Totals are now added in whole poisha.
 */
class CartMoneySumTest extends TestCase
{
    /**
     * @return list<array{unit_price: float, quantity: int}>
     */
    private function lines(): array
    {
        return [
            ['unit_price' => 0.70, 'quantity' => 1],
            ['unit_price' => 0.10, 'quantity' => 1],
            ['unit_price' => 0.10, 'quantity' => 1],
            ['unit_price' => 0.10, 'quantity' => 1],
        ];
    }

    public function test_line_totals_add_up_to_the_exact_amount(): void
    {
        $this->assertSame(1.0, Money::lineTotal($this->lines()));
        $this->assertSame(100, Money::poisha(1.0));
        $this->assertSame(1999, Money::poisha('19.99'));
        $this->assertSame(1234.5, Money::fromPoisha(123450));
    }

    public function test_the_cart_subtotal_reaches_a_minimum_it_exactly_meets(): void
    {
        Session::put('b2bhaat_cart_items', $this->lines());

        $subtotal = app(CartSessionService::class)->getSubtotal();

        $this->assertSame(1.0, $subtotal);
        $this->assertFalse($subtotal < 1.00, 'A ৳1.00 cart was treated as below a ৳1.00 minimum');
    }
}
