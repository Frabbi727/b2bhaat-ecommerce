<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Livewire\Storefront\CheckoutComponent;
use PHPUnit\Framework\TestCase;

/**
 * Roadmap 5.3: the checkout message names every changed product with its old and new price, and does not claim a
 * reason it can't know.
 */
class CheckoutPriceChangeMessageTest extends TestCase
{
    public function test_several_changes_are_each_named_with_old_and_new_price(): void
    {
        $message = CheckoutComponent::priceChangeMessage([
            ['title' => 'Steel Nails', 'old_price' => 110.0, 'new_price' => 95.0],
            ['title' => 'Cotton Shirt', 'old_price' => 450.0, 'new_price' => 500.0],
        ]);

        $this->assertSame(
            'Some prices changed since you added them: Steel Nails ৳110.00 → ৳95.00, Cotton Shirt ৳450.00 → ৳500.00. '
            .'Prices can change when a sale ends or the seller updates them. Please check your order and place it again.',
            $message,
        );
        $this->assertStringNotContainsString('flash sale ended', $message);
    }
}
