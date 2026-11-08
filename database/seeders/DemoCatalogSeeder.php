<?php

namespace Database\Seeders;

use App\Enums\ProductApprovalStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\OrderTrackingLog;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\SubOrder;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('slug', 'super_admin')->firstOrFail();
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();
        $wholesaleRole = Role::where('slug', 'wholesale_buyer')->firstOrFail();
        $retailRole = Role::where('slug', 'retail_buyer')->firstOrFail();

        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $wholesaleChannel = SalesChannel::where('slug', SalesChannel::WHOLESALE)->firstOrFail();
        $bothChannel = SalesChannel::where('slug', SalesChannel::BOTH)->firstOrFail();
        $pieceUnit = Unit::where('symbol', 'pc')->firstOrFail();

        // 1. Admin User
        User::firstOrCreate(
            ['email' => 'admin@b2bhaat.com'],
            [
                'role_id' => $adminRole->id,
                'name' => 'B2B Haat Admin',
                'phone' => '+8801700000001',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        // 2. Verified Seller User & Store
        $seller = User::firstOrCreate(
            ['email' => 'seller@nailsfactory.com'],
            [
                'role_id' => $sellerRole->id,
                'name' => 'Shahidul Alam',
                'phone' => '+8801700000002',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );
        $seller->update(['role_id' => $sellerRole->id]);

        SellerProfile::firstOrCreate(
            ['user_id' => $seller->id],
            [
                'store_name' => 'NailsFactory Ltd.',
                'store_slug' => 'nailsfactory-ltd',
                'trade_license_number' => 'TRAD/DNCC/2026/09912',
                'tax_id_number' => 'TIN-9876543210',
                'commission_rate_percentage' => 10.00,
                'is_approved' => true,
            ]
        );

        SellerWallet::firstOrCreate(
            ['seller_id' => $seller->id],
            [
                'available_balance' => 15000.00,
                'escrow_held_balance' => 4500.00,
            ]
        );

        // 3. Wholesale Buyer
        $wholesaleUser = User::firstOrCreate(
            ['email' => 'buyer@boutique.com'],
            [
                'role_id' => $wholesaleRole->id,
                'name' => 'Rahima Begum (Chic Boutique)',
                'phone' => '+8801700000003',
                'password' => Hash::make('password'),
                'is_active' => true,
                'is_wholesale_approved' => true,
                'wholesale_approved_at' => now(),
            ]
        );

        // 4. Retail Buyer
        $retailUser = User::firstOrCreate(
            ['email' => 'customer@retail.com'],
            [
                'role_id' => $retailRole->id,
                'name' => 'Tanvir Hasan',
                'phone' => '+8801700000004',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        CustomerAddress::firstOrCreate(
            ['user_id' => $retailUser->id, 'address_title' => 'Home'],
            [
                'recipient_name' => 'Tanvir Hasan',
                'recipient_phone' => '+8801700000004',
                'street_address' => 'House 14, Road 5, Dhanmondi',
                'city' => 'Dhaka',
                'postal_code' => '1205',
                'is_default' => true,
            ]
        );

        CustomerAddress::firstOrCreate(
            ['user_id' => $retailUser->id, 'address_title' => 'Uttara Branch Outlet'],
            [
                'recipient_name' => 'Tanvir Hasan',
                'recipient_phone' => '+8801700000004',
                'street_address' => 'Plot 22, Sector 3, Uttara',
                'city' => 'Dhaka',
                'postal_code' => '1230',
                'is_default' => false,
            ]
        );

        CustomerAddress::firstOrCreate(
            ['user_id' => $wholesaleUser->id, 'address_title' => 'Boutique Salon HQ'],
            [
                'recipient_name' => 'Farhana Akter',
                'recipient_phone' => '+8801700000003',
                'street_address' => 'Road 11, Block D, Banani',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'is_default' => true,
            ]
        );

        CustomerAddress::firstOrCreate(
            ['user_id' => $wholesaleUser->id, 'address_title' => 'Chittagong Distribution Hub'],
            [
                'recipient_name' => 'Karim Ullah',
                'recipient_phone' => '+8801700000003',
                'street_address' => 'GEC Circle, Nasirabad',
                'city' => 'Chittagong',
                'postal_code' => '4000',
                'is_default' => false,
            ]
        );

        // 5. Categories
        $parentCat = Category::firstOrCreate(
            ['slug' => 'beauty-personal-care'],
            [
                'name' => 'Beauty & Personal Care',
                'icon_url' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=100',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        $childCat = Category::firstOrCreate(
            ['slug' => 'false-nails-accessories'],
            [
                'parent_id' => $parentCat->id,
                'name' => 'False Nails & Accessories',
                'icon_url' => 'https://images.unsplash.com/photo-1632345031435-8727f6897d53?w=100',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        // The demo nail seller's full catalogue sheet uses these, so a reseeded database can import it again
        foreach ([
            'Nail Polish & Gel',
            'Nail Tools & Implements',
            'Nail Art & Charms',
            'Acrylic & Polygel Systems',
            'Nail Care & Liquids',
            'Salon Equipment & Lamps',
        ] as $position => $nailCategory) {
            Category::firstOrCreate(
                ['slug' => Str::slug($nailCategory)],
                ['parent_id' => $parentCat->id, 'name' => $nailCategory, 'sort_order' => 10 + $position, 'is_active' => true]
            );
        }

        // 6. Brand
        $brand = Brand::firstOrCreate(
            ['slug' => 'glamourpro'],
            [
                'name' => 'GlamourPro',
                'logo_url' => 'https://images.unsplash.com/photo-1599305445671-ac291c95aaa9?w=100',
                'is_active' => true,
            ]
        );

        Brand::firstOrCreate(['slug' => 'nails-factory-ltd'], ['name' => 'Nails Factory Ltd', 'is_active' => true]);

        // 7. Attributes & Values
        $colorAttr = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color']);
        $sizeAttr = Attribute::firstOrCreate(['slug' => 'length'], ['name' => 'Length']);

        $pinkVal = AttributeValue::firstOrCreate(
            ['attribute_id' => $colorAttr->id, 'value' => 'Pink'],
            ['color_code' => '#F472B6']
        );
        $blackVal = AttributeValue::firstOrCreate(
            ['attribute_id' => $colorAttr->id, 'value' => 'Black'],
            ['color_code' => '#18181B']
        );
        $redVal = AttributeValue::firstOrCreate(
            ['attribute_id' => $colorAttr->id, 'value' => 'Ruby Red'],
            ['color_code' => '#DC2626']
        );

        $medVal = AttributeValue::firstOrCreate(
            ['attribute_id' => $sizeAttr->id, 'value' => 'Medium']
        );
        $longVal = AttributeValue::firstOrCreate(
            ['attribute_id' => $sizeAttr->id, 'value' => 'Long']
        );

        // 8. Sample Dual-Channel Product
        $product = Product::firstOrCreate(
            ['slug' => 'luxury-press-on-fake-nails-set'],
            [
                'seller_id' => $seller->id,
                'category_id' => $childCat->id,
                'brand_id' => $brand->id,
                'sales_channel_id' => $bothChannel->id,
                'unit_id' => $pieceUnit->id,
                'title' => 'Luxury Press-on Fake Nails Set',
                'short_description' => 'Salon quality reusable artificial nails with adhesive tabs.',
                'full_description' => 'Premium reusable artificial nail extensions suitable for salon retail and bulk boutique distribution. Includes 24 pieces in 12 sizes with prep pad and nail file.',
                'buy_price' => 45.00,
                'has_variations' => true,
                'is_active' => true,
            ]
        );

        // Demo products are live from the start, so they skip the review queue
        $product->forceFill([
            'approval_status' => ProductApprovalStatus::Approved,
            'approved_at' => $product->approved_at ?? now(),
        ])->save();

        // Product Primary Image
        ProductImage::firstOrCreate(
            ['product_id' => $product->id, 'sort_order' => 1],
            [
                'image_url' => 'https://images.unsplash.com/photo-1632345031435-8727f6897d53?w=800',
                'is_primary' => true,
            ]
        );

        // Retail Price: min_quantity 1, price 250.00
        ProductPrice::firstOrCreate(
            [
                'product_id' => $product->id,
                'variation_id' => null,
                'sales_channel_id' => $retailChannel->id,
                'min_quantity' => 1,
            ],
            [
                'max_quantity' => null,
                'unit_price' => 250.00,
            ]
        );

        // Wholesale Price Tiers:
        // Tier 1: 50-99 units @ ৳110.00
        ProductPrice::firstOrCreate(
            [
                'product_id' => $product->id,
                'variation_id' => null,
                'sales_channel_id' => $wholesaleChannel->id,
                'min_quantity' => 50,
            ],
            [
                'max_quantity' => 99,
                'unit_price' => 110.00,
            ]
        );

        // Tier 2: 100-249 units @ ৳95.00 (Save 14%)
        ProductPrice::firstOrCreate(
            [
                'product_id' => $product->id,
                'variation_id' => null,
                'sales_channel_id' => $wholesaleChannel->id,
                'min_quantity' => 100,
            ],
            [
                'max_quantity' => 249,
                'unit_price' => 95.00,
            ]
        );

        // Tier 3: 250+ units @ ৳85.00 (Save 23%)
        ProductPrice::firstOrCreate(
            [
                'product_id' => $product->id,
                'variation_id' => null,
                'sales_channel_id' => $wholesaleChannel->id,
                'min_quantity' => 250,
            ],
            [
                'max_quantity' => null,
                'unit_price' => 85.00,
            ]
        );

        // Variations
        $variation1 = ProductVariation::firstOrCreate(
            ['sku' => 'NAIL-PNK-MED'],
            [
                'product_id' => $product->id,
                'buy_price' => 45.00,
                'stock_quantity' => 500,
                'is_active' => true,
            ]
        );
        $variation1->attributeValues()->syncWithoutDetaching([$pinkVal->id, $medVal->id]);

        $variation2 = ProductVariation::firstOrCreate(
            ['sku' => 'NAIL-BLK-LNG'],
            [
                'product_id' => $product->id,
                'buy_price' => 50.00,
                'stock_quantity' => 300,
                'is_active' => true,
            ]
        );
        $variation2->attributeValues()->syncWithoutDetaching([$blackVal->id, $longVal->id]);

        $variation3 = ProductVariation::firstOrCreate(
            ['sku' => 'NAIL-RED-MED'],
            [
                'product_id' => $product->id,
                'buy_price' => 45.00,
                'stock_quantity' => 200,
                'is_active' => true,
            ]
        );
        $variation3->attributeValues()->syncWithoutDetaching([$redVal->id, $medVal->id]);

        // 9. Sample Demo Order for Customer & Seller Fulfillment Testing
        $confirmedStatus = OrderStatus::where('slug', 'confirmed')->first();
        $paidStatus = PaymentStatus::where('slug', 'paid')->first();
        $shippedStatus = FulfillmentStatus::where('slug', 'shipped')->first();
        $codMethod = PaymentMethod::where('slug', 'cod')->first();

        if ($confirmedStatus && $paidStatus && $codMethod && $shippedStatus) {
            $demoOrder = Order::firstOrCreate(
                ['order_number' => 'ORD-20261028-DEMO01'],
                [
                    'customer_id' => $retailUser->id,
                    'order_status_id' => $confirmedStatus->id,
                    'payment_status_id' => $paidStatus->id,
                    'payment_method_id' => $codMethod->id,
                    'total_amount' => 570.00,
                    'shipping_amount' => 70.00,
                    'shipping_address' => [
                        'recipient_name' => 'Tanvir Hasan',
                        'recipient_phone' => '+8801700000004',
                        'street_address' => 'House 14, Road 5, Dhanmondi',
                        'city' => 'Dhaka',
                        'postal_code' => '1205',
                        'delivery_zone' => 'inside_dhaka',
                    ],
                ]
            );

            $demoSubOrder = SubOrder::firstOrCreate(
                ['sub_order_number' => 'PKG-20261028-001'],
                [
                    'parent_order_id' => $demoOrder->id,
                    'seller_id' => $seller->id,
                    'fulfillment_status_id' => $shippedStatus->id,
                    'courier_name' => 'Steadfast',
                    'tracking_number' => 'STDFST-DHAKA-8899',
                    'subtotal' => 500.00,
                    'commission_amount' => 50.00,
                    'shipping_amount' => 70.00,
                ]
            );

            OrderItem::firstOrCreate(
                [
                    'sub_order_id' => $demoSubOrder->id,
                    'product_id' => $product->id,
                    'variation_id' => $variation1->id,
                ],
                [
                    'sales_channel_id' => $retailChannel->id,
                    'quantity' => 2,
                    'unit_price' => 250.00,
                    'total_price' => 500.00,
                ]
            );

            OrderTrackingLog::firstOrCreate(
                [
                    'sub_order_id' => $demoSubOrder->id,
                    'title' => 'Dispatched via Steadfast Courier',
                ],
                [
                    'fulfillment_status_id' => $shippedStatus->id,
                    'hub_location' => 'Tejgaon Central Sorting Hub',
                    'description' => 'Package picked up from supplier and in transit to delivery hub.',
                    'logged_at' => now()->subHours(12),
                ]
            );

            // 8. Sample Reviews
            ProductReview::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'user_id' => $retailUser->id,
                ],
                [
                    'order_id' => $demoOrder->id,
                    'sub_order_id' => $demoSubOrder->id,
                    'rating' => 5,
                    'title' => 'Superior UV Finish & Long Lasting!',
                    'comment' => 'Received the 15ml Gel Polish in Dhaka via Steadfast in under 24 hours. The Ruby Red finish is ultra vibrant and cures completely in 60s under LED. Perfect retail packaging.',
                    'is_verified_purchase' => true,
                    'is_approved' => true,
                    'seller_reply' => 'Thank you for your review! We formulate all gel polishes with high-pigment European resin. Looking forward to your next order!',
                    'seller_replied_at' => now()->subHours(6),
                ]
            );

            ProductReview::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'user_id' => $wholesaleUser->id,
                ],
                [
                    'rating' => 5,
                    'title' => 'Top Quality for Wholesale Salon Distribution',
                    'comment' => 'Procured a bulk carton lot for our beauty salons across Dhanmondi and Banani. The MOQ pricing is unbeatable directly from the factory, and customer feedback has been exceptional.',
                    'is_verified_purchase' => false,
                    'is_approved' => true,
                    'seller_reply' => 'We appreciate your business! Feel free to request a custom RFQ anytime for custom branding or larger batch runs.',
                    'seller_replied_at' => now()->subHours(2),
                ]
            );
        }
    }
}
