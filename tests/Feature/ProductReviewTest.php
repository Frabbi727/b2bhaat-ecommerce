<?php

namespace Tests\Feature;

use App\Livewire\Storefront\ProductDetail;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
    }

    public function test_product_detail_page_renders_reviews_and_ratings_summary(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->assertStatus(200)
            ->assertSee('Buyer Reviews')
            ->assertSee('Superior UV Finish & Long Lasting!')
            ->assertSee('Verified Purchase')
            ->assertSee('Factory Response from NailsFactory Ltd.')
            ->assertSee('5.0');
    }

    public function test_guest_cannot_submit_review(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('reviewRating', 5)
            ->set('reviewTitle', 'Guest review attempt')
            ->set('reviewComment', 'Trying to post without login')
            ->call('submitReview')
            ->assertSet('reviewErrorMessage', 'You must be logged in to submit a review.');
    }

    public function test_review_submission_validates_inputs(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $user = User::where('email', 'buyer@boutique.com')->firstOrFail();

        $this->actingAs($user);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('reviewRating', 0)
            ->set('reviewComment', 'hi')
            ->call('submitReview')
            ->assertHasErrors(['reviewRating', 'reviewComment']);
    }

    public function test_verified_purchaser_receives_verified_purchase_badge(): void
    {
        // customer@retail.com has an OrderItem in DemoCatalogSeeder for this product
        $retailUser = User::where('email', 'customer@retail.com')->firstOrFail();
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->actingAs($retailUser);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('reviewRating', 5)
            ->set('reviewTitle', 'Updated Authentic Feedback')
            ->set('reviewComment', 'Truly impressed by the shine, viscosity, and fast curing under UV lamp.')
            ->call('submitReview')
            ->assertSet('reviewSuccessMessage', 'Thank you! Your verified purchase review has been published.');

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'user_id' => $retailUser->id,
            'is_verified_purchase' => true,
            'rating' => 5,
        ]);
    }

    public function test_non_purchasing_user_can_submit_unverified_review(): void
    {
        $role = Role::where('slug', 'retail_buyer')->firstOrFail();
        $newBuyer = User::create([
            'role_id' => $role->id,
            'name' => 'New Customer',
            'email' => 'newcustomer@test.com',
            'phone' => '+8801700000099',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        $this->actingAs($newBuyer);

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->set('reviewRating', 4)
            ->set('reviewTitle', 'Promising item')
            ->set('reviewComment', 'Excited to place an order after checking sample swatches in store.')
            ->call('submitReview')
            ->assertSet('reviewSuccessMessage', 'Thank you! Your customer review has been published.');

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'user_id' => $newBuyer->id,
            'is_verified_purchase' => false,
            'rating' => 4,
        ]);
    }

    public function test_seller_can_reply_to_review(): void
    {
        $seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $review = ProductReview::firstOrFail();

        $this->actingAs($seller);

        $replyText = 'Thank you for your valuable feedback! We ensure all batches pass ISO lab checks.';
        $review->update([
            'seller_reply' => $replyText,
            'seller_replied_at' => now(),
        ]);

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'seller_reply' => $replyText,
        ]);
    }

    public function test_reviews_filter_by_star_and_verified(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();

        Livewire::test(ProductDetail::class, ['slug' => $product->slug])
            ->call('setFilterRating', '5')
            ->assertSet('reviewFilterRating', '5')
            ->call('setFilterRating', 'verified')
            ->assertSet('reviewFilterRating', 'verified')
            ->call('setFilterRating', 'all')
            ->assertSet('reviewFilterRating', 'all');
    }

    public function test_guest_review_link_points_to_login(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $productUrl = route('catalog.show', $product->slug);

        $html = $this->get($productUrl)->assertOk()->getContent();

        // The review call-to-action must use the real login (demo links in the dev header are separate)
        $this->assertMatchesRegularExpression('#<a href="([^"]+)"[^>]*>\s*Sign In to Review#', $html);
        preg_match('#<a href="([^"]+)"[^>]*>\s*Sign In to Review#', $html, $match);
        $this->assertStringNotContainsString('/demo-login', $match[1]);
        $this->assertSame(e(route('login', ['redirect' => $productUrl])), $match[1]);
    }

    public function test_login_redirect_param_returns_to_product_after_sign_in(): void
    {
        $product = Product::where('slug', 'luxury-press-on-fake-nails-set')->firstOrFail();
        $productUrl = route('catalog.show', $product->slug);

        $this->get(route('login', ['redirect' => $productUrl]))->assertOk();
        $this->assertSame($productUrl, session('url.intended'));
    }

    public function test_login_redirect_param_ignores_external_urls(): void
    {
        $this->get(route('login', ['redirect' => 'https://evil.example/phish']))->assertOk();
        $this->assertNull(session('url.intended'));

        $this->get(route('login', ['redirect' => '//evil.example/phish']))->assertOk();
        $this->assertNull(session('url.intended'));
    }
}
