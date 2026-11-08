<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Seller\Resources\RfqRequests\Pages\ListRfqRequests;
use App\Jobs\SendSmsJob;
use App\Livewire\Storefront\ProductDetail;
use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\User;
use App\Services\Rfq\RfqNotificationService;
use App\Services\Sms\Support\PhoneNormalizer;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\PromotionsAndStoreSettingsSeeder;
use Database\Seeders\SystemLookupSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RfqSmsNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(PromotionsAndStoreSettingsSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->buyer = User::where('email', 'buyer@boutique.com')->firstOrFail();
        $this->product = Product::where('seller_id', $this->seller->id)->firstOrFail();
    }

    public function test_submitting_rfq_on_product_detail_dispatches_sms_to_seller(): void
    {
        Queue::fake([SendSmsJob::class]);

        $this->actingAs($this->buyer);

        Livewire::test(ProductDetail::class, ['slug' => $this->product->slug])
            ->set('rfqBuyerName', 'Boutique Merchant Ltd')
            ->set('rfqBuyerEmail', 'procurement@boutique.com')
            ->set('rfqBuyerPhone', '01711223344')
            ->set('rfqCompanyName', 'Boutique Chain Dhaka')
            ->set('rfqTargetQuantity', 300)
            ->set('rfqTargetPrice', 1500.00)
            ->set('rfqDestinationDistrict', 'Dhaka')
            ->set('rfqSpecifications', 'Custom color requested.')
            ->call('submitRfq')
            ->assertSet('rfqSuccess', true);

        $rfq = RfqRequest::where('product_id', $this->product->id)->latest('id')->firstOrFail();

        Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($rfq) {
            return $job->phone === PhoneNormalizer::normalize($this->seller->phone)
                && $job->purpose === 'rfq_new_inquiry'
                && str_contains($job->message, "New Wholesale RFQ #{$rfq->id}")
                && str_contains($job->message, Str::limit($this->product->title, 40))
                && str_contains($job->message, '300 pcs');
        });
    }

    public function test_seller_sending_quote_dispatches_sms_to_buyer(): void
    {
        Queue::fake([SendSmsJob::class]);

        Filament::setCurrentPanel(Filament::getPanel('seller'));
        $this->actingAs($this->seller);

        $rfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => 'Boutique Buyer',
            'buyer_email' => 'buyer@boutique.com',
            'buyer_phone' => '01711223344',
            'company_name' => 'Dhaka Retail Ltd',
            'target_quantity' => 200,
            'target_unit_price' => 100.00,
            'destination_district' => 'Dhaka',
            'status' => 'pending',
        ]);

        Livewire::test(ListRfqRequests::class)
            ->callTableAction('send_quote', $rfq, [
                'seller_quoted_price' => 115.50,
                'validity_days' => 14,
                'dispatch_days' => 5,
                'seller_notes' => 'Quotation valid for 14 days.',
            ])
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($rfq) {
            return $job->phone === PhoneNormalizer::normalize('01711223344')
                && $job->purpose === 'rfq_quote_sent'
                && str_contains($job->message, "Factory has quoted your RFQ #{$rfq->id}")
                && str_contains($job->message, Str::limit($this->product->title, 40))
                && str_contains($job->message, 'BDT 115.50/pc')
                && str_contains($job->message, route('rfq.quotation.print', $rfq->id));
        });
    }

    public function test_buyer_accepting_quotation_dispatches_sms_to_seller(): void
    {
        Queue::fake([SendSmsJob::class]);

        $rfq = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->seller->id,
            'buyer_id' => $this->buyer->id,
            'buyer_name' => $this->buyer->name,
            'buyer_email' => $this->buyer->email,
            'buyer_phone' => '01711223344',
            'company_name' => 'Boutique Chain Dhaka',
            'target_quantity' => 150,
            'target_unit_price' => 1400.00,
            'seller_quoted_price' => 1450.00,
            'destination_district' => 'Dhaka',
            'status' => 'quoted',
        ]);

        $this->actingAs($this->buyer)
            ->post(route('rfq.quotation.accept', $rfq->id))
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHas('success');

        Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($rfq) {
            return $job->phone === PhoneNormalizer::normalize($this->seller->phone)
                && $job->purpose === 'rfq_quote_accepted'
                && str_contains($job->message, "Buyer has accepted Quotation #{$rfq->id}")
                && str_contains($job->message, Str::limit($this->product->title, 40))
                && str_contains($job->message, '150 pcs')
                && str_contains($job->message, 'proceeded to checkout');
        });
    }

    public function test_missing_or_invalid_phones_do_not_throw_and_do_not_dispatch_sms(): void
    {
        Queue::fake([SendSmsJob::class]);

        $service = app(RfqNotificationService::class);

        // Seller without phone
        $sellerWithoutPhone = User::factory()->create([
            'phone' => null,
        ]);

        $rfqWithoutSellerPhone = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $sellerWithoutPhone->id,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'buyer@test.com',
            'buyer_phone' => '',
            'target_quantity' => 50,
            'destination_district' => 'Dhaka',
            'status' => 'pending',
            'seller_quoted_price' => 200.00,
        ]);

        // Should not throw and should not dispatch
        $service->notifySellerOfNewInquiry($rfqWithoutSellerPhone);
        $service->notifyBuyerOfQuotation($rfqWithoutSellerPhone);
        $service->notifySellerOfAcceptedQuote($rfqWithoutSellerPhone);

        // Also test with completely null buyer_phone attribute on unpersisted model
        $unpersistedRfq = new RfqRequest([
            'product_id' => $this->product->id,
            'seller_id' => $sellerWithoutPhone->id,
            'buyer_name' => 'Test Buyer',
            'buyer_phone' => null,
            'target_quantity' => 10,
        ]);
        $unpersistedRfq->setRelation('seller', $sellerWithoutPhone);
        $service->notifySellerOfNewInquiry($unpersistedRfq);
        $service->notifyBuyerOfQuotation($unpersistedRfq);
        $service->notifySellerOfAcceptedQuote($unpersistedRfq);

        // Seller and buyer with invalid phone string
        $rfqWithInvalidPhones = RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $sellerWithoutPhone->id,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'buyer@test.com',
            'buyer_phone' => 'not-a-phone-123',
            'target_quantity' => 50,
            'destination_district' => 'Dhaka',
            'status' => 'pending',
            'seller_quoted_price' => 200.00,
        ]);

        $service->notifySellerOfNewInquiry($rfqWithInvalidPhones);
        $service->notifyBuyerOfQuotation($rfqWithInvalidPhones);
        $service->notifySellerOfAcceptedQuote($rfqWithInvalidPhones);

        Queue::assertNothingPushed();
    }
}
