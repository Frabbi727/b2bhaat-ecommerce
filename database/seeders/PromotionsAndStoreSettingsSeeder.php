<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\Role;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\StoreSetting;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PromotionsAndStoreSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Store Global Settings
        StoreSetting::set('store_name', 'B2B Haat', 'general');
        StoreSetting::set('helpline_phone', '+880 9612-445566', 'general');
        StoreSetting::set('support_email', 'support@b2bhaat.com', 'general');
        StoreSetting::set('delivery_charge_inside_dhaka', '70.00', 'logistics');
        StoreSetting::set('delivery_charge_outside_dhaka', '130.00', 'logistics');
        StoreSetting::set('free_delivery_threshold', '2500.00', 'logistics');
        StoreSetting::set('express_delivery_charge', '150.00', 'logistics');
        StoreSetting::set('return_window_days', '7', 'policies');

        // 2. Promotional Banners
        Banner::updateOrCreate(
            ['title' => 'Haier 4K Mini LED Google TV Fest'],
            [
                'subtitle' => 'Unmatched Contrast & 120Hz Smooth Gaming with Dolby Atmos',
                'badge_text' => 'Flagship Arrival',
                'desktop_image_url' => 'https://images.unsplash.com/photo-1593784991095-a205069470b6?auto=format&fit=crop&w=1600&q=80',
                'mobile_image_url' => 'https://images.unsplash.com/photo-1593784991095-a205069470b6?auto=format&fit=crop&w=800&q=80',
                'link_url' => '/product/haier-55-inch-mini-led-4k-uhd-google-tv-h55m80gux',
                'button_text' => 'Explore 4K Mini LED',
                'position' => 'hero_slider',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        Banner::updateOrCreate(
            ['title' => 'B2B Electronics Wholesale Mega Sourcing'],
            [
                'subtitle' => 'Factory Direct Tiered Bulk MOQ Pricing with 48h Escrow Guarantee',
                'badge_text' => 'Verified Factory Direct',
                'desktop_image_url' => 'https://images.unsplash.com/photo-1550009158-9ebf69173e03?auto=format&fit=crop&w=1600&q=80',
                'mobile_image_url' => 'https://images.unsplash.com/photo-1550009158-9ebf69173e03?auto=format&fit=crop&w=800&q=80',
                'link_url' => '/',
                'button_text' => 'Source Factory Direct',
                'position' => 'hero_slider',
                'sort_order' => 2,
                'is_active' => true,
            ]
        );

        Banner::updateOrCreate(
            ['title' => 'Premium Audio & Noise Cancelling Flagships'],
            [
                'subtitle' => 'Sony, Bose & Sennheiser Official Bangladesh Warranty',
                'badge_text' => 'Official Warranty',
                'desktop_image_url' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=1600&q=80',
                'mobile_image_url' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=800&q=80',
                'link_url' => '/product/sony-wh-1000xm5-wireless-noise-canceling-headphones',
                'button_text' => 'Shop Premium Audio',
                'position' => 'hero_slider',
                'sort_order' => 3,
                'is_active' => true,
            ]
        );

        Banner::updateOrCreate(
            ['title' => 'Islandwide Express Shipping Promo'],
            [
                'subtitle' => 'Free islandwide delivery on all consumer retail orders above ৳2,500',
                'badge_text' => 'Limited Time',
                'desktop_image_url' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1200&q=80',
                'link_url' => '/',
                'position' => 'top_strip',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        // 3. Promotional Coupons
        Coupon::updateOrCreate(
            ['code' => 'WELCOME10'],
            [
                'name' => 'New Customer Welcome Coupon',
                'description' => '10% discount up to ৳500 on your first shopping order over ৳1,000.',
                'discount_type' => Coupon::TYPE_PERCENTAGE,
                'discount_value' => 10.00,
                'min_order_amount' => 1000.00,
                'max_discount_amount' => 500.00,
                'usage_limit_total' => 1000,
                'usage_limit_per_user' => 1,
                'is_active' => true,
            ]
        );

        Coupon::updateOrCreate(
            ['code' => 'B2BMEGA'],
            [
                'name' => 'Wholesale Bulk Sourcing Discount',
                'description' => 'Flat ৳1,000 instant discount on commercial wholesale orders over ৳15,000.',
                'discount_type' => Coupon::TYPE_FIXED,
                'discount_value' => 1000.00,
                'min_order_amount' => 15000.00,
                'max_discount_amount' => null,
                'usage_limit_total' => 500,
                'usage_limit_per_user' => 5,
                'is_active' => true,
            ]
        );

        Coupon::updateOrCreate(
            ['code' => 'FLASH500'],
            [
                'name' => 'Flash Sale Instant ৳500 Voucher',
                'description' => 'Special instant ৳500 savings on consumer electronics over ৳5,000.',
                'discount_type' => Coupon::TYPE_FIXED,
                'discount_value' => 500.00,
                'min_order_amount' => 5000.00,
                'max_discount_amount' => 500.00,
                'usage_limit_total' => 300,
                'usage_limit_per_user' => 1,
                'is_active' => true,
            ]
        );

        // 4. Roles & Channels
        $retailChannel = SalesChannel::where('slug', SalesChannel::RETAIL)->firstOrFail();
        $wholesaleChannel = SalesChannel::where('slug', SalesChannel::WHOLESALE)->firstOrFail();
        $bothChannel = SalesChannel::where('slug', SalesChannel::BOTH)->firstOrFail();
        $pieceUnit = Unit::where('symbol', 'pc')->firstOrFail();
        $sellerRole = Role::where('slug', 'seller')->firstOrFail();

        // Electronics Seller
        $electronicsSeller = User::firstOrCreate(
            ['email' => 'haier.official@b2bhaat.com'],
            [
                'role_id' => $sellerRole->id,
                'name' => 'Haier Official Store Bangladesh',
                'phone' => '+8801711223344',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        SellerProfile::firstOrCreate(
            ['user_id' => $electronicsSeller->id],
            [
                'store_name' => 'Haier Electronics Direct',
                'store_slug' => 'haier-electronics-direct',
                'trade_license_number' => 'TRAD/DSCC/2026/88112',
                'tax_id_number' => 'TIN-4455667788',
                'commission_rate_percentage' => 8.00,
                'is_approved' => true,
            ]
        );

        // Categories
        $tvCat = Category::firstOrCreate(
            ['slug' => 'tvs-and-entertainment'],
            [
                'name' => 'TVs & Entertainment',
                'icon_url' => 'https://images.unsplash.com/photo-1593784991095-a205069470b6?auto=format&fit=crop&w=120&q=80',
                'sort_order' => 2,
                'is_active' => true,
            ]
        );

        $audioCat = Category::firstOrCreate(
            ['slug' => 'audio-and-headphones'],
            [
                'name' => 'Audio & Headphones',
                'icon_url' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=120&q=80',
                'sort_order' => 3,
                'is_active' => true,
            ]
        );

        $phoneCat = Category::firstOrCreate(
            ['slug' => 'smartphones-and-gadgets'],
            [
                'name' => 'Smartphones & Gadgets',
                'icon_url' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=120&q=80',
                'sort_order' => 4,
                'is_active' => true,
            ]
        );

        // Brands
        $haierBrand = Brand::firstOrCreate(['slug' => 'haier'], ['name' => 'Haier', 'is_active' => true]);
        $sonyBrand = Brand::firstOrCreate(['slug' => 'sony'], ['name' => 'Sony', 'is_active' => true]);
        $samsungBrand = Brand::firstOrCreate(['slug' => 'samsung'], ['name' => 'Samsung', 'is_active' => true]);

        // Screen Size Attribute
        $sizeAttr = Attribute::firstOrCreate(['slug' => 'screen-size'], ['name' => 'Screen Size']);
        $size55 = AttributeValue::firstOrCreate(['attribute_id' => $sizeAttr->id, 'value' => '55 Inch']);
        $size65 = AttributeValue::firstOrCreate(['attribute_id' => $sizeAttr->id, 'value' => '65 Inch']);

        // Color Attribute
        $colorAttr = Attribute::where('slug', 'color')->first() ?? Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color']);
        $blackVal = AttributeValue::firstOrCreate(['attribute_id' => $colorAttr->id, 'value' => 'Titanium Black'], ['color_code' => '#18181B']);
        $silverVal = AttributeValue::firstOrCreate(['attribute_id' => $colorAttr->id, 'value' => 'Platinum Silver'], ['color_code' => '#D1D5DB']);

        // 5. SEED FLAGSHIP PICKABOO REFERENCE PRODUCT: Haier 55" Mini LED 4K UHD Google TV H55M80GUX
        $tvProduct = Product::updateOrCreate(
            ['slug' => 'haier-55-inch-mini-led-4k-uhd-google-tv-h55m80gux'],
            [
                'seller_id' => $electronicsSeller->id,
                'category_id' => $tvCat->id,
                'brand_id' => $haierBrand->id,
                'sales_channel_id' => $bothChannel->id,
                'unit_id' => $pieceUnit->id,
                'title' => 'Haier 55 Inch Mini LED 4K UHD Google TV (H55M80GUX)',
                'sku' => 'H55M80GUX-55',
                'model_number' => 'H55M80GUX',
                'regular_price' => 115000.00,
                'buy_price' => 68000.00,
                'warranty_period' => '4 Years Panel, 2 Years Parts, 2 Years Free Service',
                'warranty_policy' => 'Official Haier Bangladesh warranty. Nationwide doorstep service via authorized Haier service centers.',
                'delivery_info' => 'Free Express Doorstep Delivery & Professional Wall Mount Installation across Dhaka within 24 hours. 2-3 business days nationwide.',
                'short_description' => '55" Mini LED 4K UHD with 500+ local dimming zones, 120Hz MEMC, Dolby Vision IQ & Atmos 30W Soundbar.',
                'full_description' => 'Experience supreme cinematic excellence with the Haier H55M80GUX Mini LED Google TV. Engineered with state-of-the-art Mini LED backlight precision, over 500 full-array local dimming zones deliver deep ink blacks and peak highlights up to 1500 nits. Featuring genuine Google TV OS with hands-free Far-Field Voice control, native 120Hz refresh rate with VRR & ALLM for gaming, and front-firing Dolby Atmos acoustic audio.',
                'specifications' => [
                    'Display' => [
                        'Screen Size' => '55 Inch (139 cm diagonal)',
                        'Display Resolution' => '4K Ultra HD (3840 x 2160)',
                        'Backlight Technology' => 'Mini LED with 500+ Local Dimming Zones',
                        'Peak Brightness' => '1500 nits Peak Luminance',
                        'Refresh Rate' => '120Hz Native (MEMC Motion Clarity)',
                        'HDR Format' => 'Dolby Vision IQ, HDR10+, HLG',
                    ],
                    'Smart TV Platform' => [
                        'Operating System' => 'Google TV (Android 14)',
                        'Voice Interaction' => 'Far-Field Hands-free Google Assistant',
                        'Processor' => 'Quad Core Cortex-A73 64-bit',
                        'RAM & Internal Storage' => '3GB RAM + 32GB High-speed Storage',
                        'App Store' => 'Google Play Store (Netflix, Prime, YouTube, Zee5)',
                        'Casting Technology' => 'Chromecast Built-in & Apple AirPlay 2',
                    ],
                    'Audio & Acoustics' => [
                        'Audio Output Power' => '30W (2 x 15W Front-firing stereo)',
                        'Sound Processing' => 'Dolby Atmos & DTS Virtual:X',
                        'Equalizer Modes' => 'Standard, Cinema, Music, News, Game',
                    ],
                    'Connectivity & Ports' => [
                        'HDMI Inputs' => '4 x HDMI 2.1 (HDMI 2 with eARC, 4K@120Hz VRR)',
                        'USB Ports' => '2 x USB 2.0 / 3.0 Media Playback',
                        'Wi-Fi' => 'Dual Band Wi-Fi 6 (2.4GHz / 5GHz)',
                        'Bluetooth' => 'Bluetooth 5.2 Two-way Audio',
                        'Ethernet RJ45' => '1 x 100Mbps LAN port',
                        'Digital Optical Audio' => '1 x Optical SPDIF Out',
                    ],
                    'Dimensions & Power' => [
                        'Dimensions with Stand' => '1226 x 776 x 279 mm',
                        'Dimensions without Stand' => '1226 x 712 x 78 mm',
                        'Gross Weight' => '16.8 kg',
                        'Power Consumption' => '220W Operating, <0.5W Standby',
                    ],
                ],
                'features' => [
                    'Mini LED Backlight with 500+ Precision Local Dimming Zones',
                    'Dolby Vision IQ & HDR10+ Dynamic Metadata Tone Mapping',
                    '120Hz High Refresh Rate with MEMC, VRR & ALLM for Next-Gen Consoles',
                    'Google TV with Hands-Free Far-field Voice Search & Personal Profiles',
                    '30W Integrated Acoustic Chamber with Dolby Atmos 3D Sound',
                    'Authorized Official Haier Bangladesh Warranty with Free Wall Mount Installation',
                ],
                'has_variations' => true,
                'is_featured' => true,
                'is_flash_sale' => true,
                'flash_sale_price' => 84900.00,
                'flash_sale_end' => now()->addDays(7),
                'views_count' => 1420,
                'sales_count' => 18,
                'is_active' => true,
            ]
        );

        // TV Images
        $tvImages = [
            ['url' => 'https://images.unsplash.com/photo-1593784991095-a205069470b6?auto=format&fit=crop&w=1000&q=80', 'order' => 1, 'primary' => true],
            ['url' => 'https://images.unsplash.com/photo-1593359677879-a4bb92f829d1?auto=format&fit=crop&w=1000&q=80', 'order' => 2, 'primary' => false],
            ['url' => 'https://images.unsplash.com/photo-1577975882846-431adc8c2009?auto=format&fit=crop&w=1000&q=80', 'order' => 3, 'primary' => false],
            ['url' => 'https://images.unsplash.com/photo-1509281373149-e957c6296406?auto=format&fit=crop&w=1000&q=80', 'order' => 4, 'primary' => false],
        ];
        foreach ($tvImages as $img) {
            ProductImage::updateOrCreate(
                ['product_id' => $tvProduct->id, 'sort_order' => $img['order']],
                ['image_url' => $img['url'], 'is_primary' => $img['primary']]
            );
        }

        // TV Retail Price: ৳88,900 (regular ৳115,000, 23% off; flash sale ৳84,900)
        ProductPrice::updateOrCreate(
            [
                'product_id' => $tvProduct->id,
                'variation_id' => null,
                'sales_channel_id' => $retailChannel->id,
                'min_quantity' => 1,
            ],
            [
                'max_quantity' => null,
                'unit_price' => 88900.00,
            ]
        );

        // TV Wholesale Price Tiers
        ProductPrice::updateOrCreate(
            [
                'product_id' => $tvProduct->id,
                'variation_id' => null,
                'sales_channel_id' => $wholesaleChannel->id,
                'min_quantity' => 5,
            ],
            [
                'max_quantity' => 9,
                'unit_price' => 74500.00,
            ]
        );
        ProductPrice::updateOrCreate(
            [
                'product_id' => $tvProduct->id,
                'variation_id' => null,
                'sales_channel_id' => $wholesaleChannel->id,
                'min_quantity' => 10,
            ],
            [
                'max_quantity' => null,
                'unit_price' => 71000.00,
            ]
        );

        // Variations: 55-inch and 65-inch
        $var55 = ProductVariation::updateOrCreate(
            ['sku' => 'H55M80GUX-55'],
            [
                'product_id' => $tvProduct->id,
                'buy_price' => 68000.00,
                'stock_quantity' => 42,
                'is_active' => true,
            ]
        );
        $var55->attributeValues()->syncWithoutDetaching([$size55->id, $blackVal->id]);

        $var65 = ProductVariation::updateOrCreate(
            ['sku' => 'H65M80GUX-65'],
            [
                'product_id' => $tvProduct->id,
                'buy_price' => 92000.00,
                'stock_quantity' => 25,
                'is_active' => true,
            ]
        );
        $var65->attributeValues()->syncWithoutDetaching([$size65->id, $blackVal->id]);

        // TV Review
        $retailUser = User::where('email', 'buyer@dhakashopper.com')->first();
        if ($retailUser) {
            ProductReview::updateOrCreate(
                ['product_id' => $tvProduct->id, 'user_id' => $retailUser->id],
                [
                    'rating' => 5,
                    'title' => 'Jaw-dropping Mini LED Black Levels & 120Hz PS5 Gaming!',
                    'comment' => 'Upgraded from standard 4K LED and the difference is night and day. 500 local dimming zones eliminate halo bloom. Wall mounting was completed within 24 hours of delivery by the official Haier engineer.',
                    'is_verified_purchase' => true,
                    'is_approved' => true,
                    'seller_reply' => 'Thank you for your feedback! The 4-year panel warranty is officially logged under your invoice.',
                    'seller_replied_at' => now()->subDay(),
                ]
            );
        }

        // 6. SECOND PRODUCT: Sony WH-1000XM5 Headphones
        $audioProduct = Product::updateOrCreate(
            ['slug' => 'sony-wh-1000xm5-wireless-noise-canceling-headphones'],
            [
                'seller_id' => $electronicsSeller->id,
                'category_id' => $audioCat->id,
                'brand_id' => $sonyBrand->id,
                'sales_channel_id' => $bothChannel->id,
                'unit_id' => $pieceUnit->id,
                'title' => 'Sony WH-1000XM5 Wireless Industry-Leading Noise Canceling Headphones',
                'sku' => 'SONY-WH1000XM5-BLK',
                'model_number' => 'WH-1000XM5',
                'regular_price' => 42000.00,
                'buy_price' => 26000.00,
                'warranty_period' => '1 Year Official Sony Warranty',
                'warranty_policy' => '1 Year Official Sony Bangladesh replacement warranty covering internal drivers and Bluetooth circuitry.',
                'delivery_info' => 'Ships same-day. 24-hour delivery inside Dhaka.',
                'short_description' => 'Industry-leading noise cancellation with 8 microphones, 30-hour battery, and Speak-to-Chat.',
                'full_description' => 'The Sony WH-1000XM5 rewrites the rules for distraction-free listening. Featuring two processors controlling eight microphones, Auto NC Optimizer, specially engineered 30mm driver unit, and crystal-clear hands-free calling with 4 beamforming microphones.',
                'specifications' => [
                    'Acoustics' => [
                        'Driver Unit' => '30mm High-Rigidity Carbon Fiber Dome',
                        'Frequency Response' => '4 Hz - 40,000 Hz',
                        'Hi-Res Audio' => 'Supported (LDAC / DSEE Extreme)',
                    ],
                    'Battery' => [
                        'Battery Life' => 'Up to 30 Hours (NC ON), 40 Hours (NC OFF)',
                        'Quick Charge' => '3 min charge gives 3 hours playback via USB-PD',
                    ],
                    'Connectivity' => [
                        'Bluetooth Version' => '5.2 with Multipoint Connection (2 devices)',
                        'Supported Codecs' => 'SBC, AAC, LDAC',
                    ],
                ],
                'features' => [
                    'Dual Processor V1 + HD Noise Cancelling Processor QN1',
                    '30-Hour Ultra Long Battery with USB-PD Fast Charging',
                    'Multipoint Bluetooth 5.2 - Switch seamlessly between laptop and phone',
                    'Multipoint Beamforming Mic with AI Noise Reduction for Crystal Clear Calls',
                ],
                'has_variations' => true,
                'is_featured' => true,
                'is_flash_sale' => false,
                'views_count' => 890,
                'sales_count' => 34,
                'is_active' => true,
            ]
        );

        ProductImage::updateOrCreate(
            ['product_id' => $audioProduct->id, 'sort_order' => 1],
            ['image_url' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=1000&q=80', 'is_primary' => true]
        );
        ProductImage::updateOrCreate(
            ['product_id' => $audioProduct->id, 'sort_order' => 2],
            ['image_url' => 'https://images.unsplash.com/photo-1583394838336-acd977736f90?auto=format&fit=crop&w=1000&q=80', 'is_primary' => false]
        );

        ProductPrice::updateOrCreate(
            [
                'product_id' => $audioProduct->id,
                'variation_id' => null,
                'sales_channel_id' => $retailChannel->id,
                'min_quantity' => 1,
            ],
            [
                'max_quantity' => null,
                'unit_price' => 34500.00,
            ]
        );

        ProductVariation::updateOrCreate(
            ['sku' => 'SONY-WH1000XM5-BLK'],
            [
                'product_id' => $audioProduct->id,
                'buy_price' => 26000.00,
                'stock_quantity' => 30,
                'is_active' => true,
            ]
        );

        // 7. THIRD PRODUCT: Samsung Galaxy S24 Ultra 5G
        $phoneProduct = Product::updateOrCreate(
            ['slug' => 'samsung-galaxy-s24-ultra-5g-12gb-256gb'],
            [
                'seller_id' => $electronicsSeller->id,
                'category_id' => $phoneCat->id,
                'brand_id' => $samsungBrand->id,
                'sales_channel_id' => $bothChannel->id,
                'unit_id' => $pieceUnit->id,
                'title' => 'Samsung Galaxy S24 Ultra 5G (12GB RAM, 256GB Storage)',
                'sku' => 'SM-S928B-256',
                'model_number' => 'SM-S928B',
                'regular_price' => 179000.00,
                'buy_price' => 125000.00,
                'warranty_period' => '1 Year Official Samsung Bangladesh Warranty',
                'warranty_policy' => '1 Year Official BTRC Approved Warranty with free screen replacement coverage under Samsung Care+.',
                'delivery_info' => 'Free Express 24-hour delivery with tamper-proof security seal.',
                'short_description' => 'Titanium Frame, Snapdragon 8 Gen 3, 200MP Quad Telephoto Camera & Galaxy AI.',
                'full_description' => 'Welcome to the era of mobile AI. With Samsung Galaxy S24 Ultra in your hands, you can unleash whole new levels of creativity, productivity, and possibility. Featuring a durable titanium frame, 6.8" Flat Dynamic AMOLED 2X 2600-nit display with Corning Gorilla Armor, and embedded S Pen.',
                'specifications' => [
                    'Display' => [
                        'Screen Size' => '6.8 Inch Dynamic AMOLED 2X',
                        'Resolution' => 'Quad HD+ (3120 x 1440)',
                        'Peak Brightness' => '2600 nits with Anti-Reflective Gorilla Armor',
                        'Refresh Rate' => '1-120Hz Adaptive LTPO',
                    ],
                    'Camera' => [
                        'Main Sensor' => '200MP Wide OIS (F1.7)',
                        'Telephoto' => '50MP 5x Optical Zoom + 10MP 3x Optical',
                        'Ultra-Wide' => '12MP Ultra-Wide with Dual Pixel AF',
                    ],
                    'Hardware' => [
                        'Processor' => 'Qualcomm Snapdragon 8 Gen 3 for Galaxy (4nm)',
                        'Battery' => '5,000 mAh with 45W Fast Charging',
                        'S Pen' => 'Integrated with 2.8ms low latency',
                    ],
                ],
                'features' => [
                    'Circle to Search with Google & Live Call Translation',
                    'Durable Titanium Shield & Corning Gorilla Armor Glass',
                    '200MP AI-Powered Nightography Zoom Camera System',
                    'BTRC Verified Official Bangladesh Unit with Samsung Care+',
                ],
                'has_variations' => true,
                'is_featured' => true,
                'is_flash_sale' => true,
                'flash_sale_price' => 149999.00,
                'flash_sale_end' => now()->addDays(5),
                'views_count' => 2100,
                'sales_count' => 28,
                'is_active' => true,
            ]
        );

        ProductImage::updateOrCreate(
            ['product_id' => $phoneProduct->id, 'sort_order' => 1],
            ['image_url' => 'https://images.unsplash.com/photo-1610945265064-0e34e5519bbf?auto=format&fit=crop&w=1000&q=80', 'is_primary' => true]
        );
        ProductImage::updateOrCreate(
            ['product_id' => $phoneProduct->id, 'sort_order' => 2],
            ['image_url' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1000&q=80', 'is_primary' => false]
        );

        ProductPrice::updateOrCreate(
            [
                'product_id' => $phoneProduct->id,
                'variation_id' => null,
                'sales_channel_id' => $retailChannel->id,
                'min_quantity' => 1,
            ],
            [
                'max_quantity' => null,
                'unit_price' => 154000.00,
            ]
        );

        ProductVariation::updateOrCreate(
            ['sku' => 'SM-S928B-256-BLK'],
            [
                'product_id' => $phoneProduct->id,
                'buy_price' => 125000.00,
                'stock_quantity' => 18,
                'is_active' => true,
            ]
        );

        // Update the existing nails product with regular price & specs
        $nailsProduct = Product::where('slug', 'luxury-press-on-fake-nails-set')->first();
        if ($nailsProduct) {
            $nailsProduct->update([
                'regular_price' => 350.00,
                'warranty_period' => '7 Days Replacement Guarantee',
                'warranty_policy' => 'Return and replacement valid if seal intact and undamaged.',
                'delivery_info' => '2-3 business days delivery via courier.',
                'model_number' => 'GLM-NAIL-24',
                'sku' => 'GLM-NAIL-SET',
                'specifications' => [
                    'General' => [
                        'Pieces' => '24 Nails in 12 Graduated Sizes',
                        'Finish' => 'Ultra High Gloss UV Gel Coated',
                        'Adhesive' => 'Pre-applied Jelly Adhesive Tabs + Liquid Glue',
                        'Material' => 'Virgin Acrylonitrile Butadiene Styrene (ABS)',
                    ],
                ],
                'features' => [
                    'Reusable salon-quality press-on extensions',
                    'Zero damage to natural nail bed',
                    'Waterproof and chip-resistant wear up to 14 days',
                ],
                'is_featured' => true,
            ]);
        }
    }
}
