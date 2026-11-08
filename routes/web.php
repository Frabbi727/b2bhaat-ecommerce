<?php

use App\Http\Controllers\Seller\ProductBulkImportController;
use App\Http\Controllers\Seller\ProductStockBulkController;
use App\Http\Controllers\Seller\ShippingLabelController;
use App\Http\Controllers\Seller\WalletStatementController;
use App\Http\Controllers\Storefront\InvoiceController;
use App\Http\Controllers\Storefront\QuotationController;
use App\Livewire\Storefront\BecomeSeller;
use App\Livewire\Storefront\CartComponent;
use App\Livewire\Storefront\CheckoutComponent;
use App\Livewire\Storefront\CustomerAddresses;
use App\Livewire\Storefront\CustomerAuth;
use App\Livewire\Storefront\CustomerOrders;
use App\Livewire\Storefront\CustomerWishlist;
use App\Livewire\Storefront\FlashSaleIndex;
use App\Livewire\Storefront\FlashSaleShow;
use App\Livewire\Storefront\OrderTracking;
use App\Livewire\Storefront\ProductCatalog;
use App\Livewire\Storefront\ProductDetail;
use App\Livewire\Storefront\SellerStore;
use App\Livewire\Storefront\StaticPage;
use App\Livewire\Storefront\WholesaleUpgrade;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Storefront & Catalog
Route::get('/', ProductCatalog::class)->name('catalog.index');

// Policy & information pages
foreach (array_keys(StaticPage::PAGES) as $staticPage) {
    Route::get('/'.$staticPage, StaticPage::class)->defaults('page', $staticPage)->name('pages.'.$staticPage);
}
Route::get('/login', CustomerAuth::class)->name('login');
Route::get('/register', CustomerAuth::class)->name('register');
Route::get('/store/{slug}', SellerStore::class)->name('store.show');
Route::get('/product/{slug}', ProductDetail::class)->name('catalog.show');
Route::get('/flash-sales', FlashSaleIndex::class)->name('flash-sales.index');
Route::get('/flash-sales/{slug}', FlashSaleShow::class)->name('flash-sales.show');
Route::get('/cart', CartComponent::class)->name('cart.index');
Route::get('/checkout', CheckoutComponent::class)->name('checkout.index');
Route::middleware('throttle:public-lookup')->group(function () {
    Route::get('/track', OrderTracking::class)->name('tracking.index');
    Route::get('/track/view', OrderTracking::class)->name('tracking.show');
    Route::get('/track/{token}', OrderTracking::class)->name('order.track.token');
    Route::get('/invoice/{token}', [InvoiceController::class, 'showByToken'])->name('order.invoice.token');
});
Route::get('/my-orders', CustomerOrders::class)->name('customer.orders')->middleware('auth');
Route::get('/my-addresses', CustomerAddresses::class)->name('customer.addresses')->middleware('auth');
Route::get('/my-wishlist', CustomerWishlist::class)->name('customer.wishlist')->middleware('auth');
Route::get('/wholesale/upgrade', WholesaleUpgrade::class)->name('wholesale.upgrade');
Route::get('/become-a-seller', BecomeSeller::class)->name('seller.register');
Route::get('/order/{order_number}/invoice', [InvoiceController::class, 'show'])->name('order.invoice')->middleware('auth');
Route::get('/rfq/{id}/quotation', [QuotationController::class, 'show'])->name('rfq.quotation.print')->middleware(['web', 'auth']);
Route::post('/rfq/{id}/accept-order', [QuotationController::class, 'acceptAndAddToCart'])->name('rfq.quotation.accept')->middleware(['web', 'auth']);
Route::post('/rfq/{id}/decline', [QuotationController::class, 'decline'])->name('rfq.quotation.decline')->middleware(['web', 'auth']);
Route::get('/seller/sub-order/{sub_order_number}/shipping-label', [ShippingLabelController::class, 'show'])->name('seller.sub_order.label')->middleware(['web', 'auth']);
Route::get('/seller/sub-order/{sub_order_number}/challan', [ShippingLabelController::class, 'showChallan'])->name('seller.sub_order.challan')->middleware(['web', 'auth']);
Route::get('/seller/wallet/statement', [WalletStatementController::class, 'showStatement'])->name('seller.wallet.statement.print')->middleware(['web', 'auth']);
Route::get('/seller/wallet/statement/export', [WalletStatementController::class, 'exportCsv'])->name('seller.wallet.statement.csv')->middleware(['web', 'auth']);
Route::get('/seller/products/stock/export', [ProductStockBulkController::class, 'exportCsv'])->name('seller.products.stock.export')->middleware(['web', 'auth']);
Route::post('/seller/products/stock/import', [ProductStockBulkController::class, 'importCsv'])->name('seller.products.stock.import')->middleware(['web', 'auth']);
Route::get('/seller/products/import/template', [ProductBulkImportController::class, 'template'])->name('seller.products.import.template')->middleware(['web', 'auth']);

// Quick Demo Persona Switcher (Convenient for Pair-Programming & Verification)
Route::get('/demo-login/{role}', function (string $role) {
    if (! app()->environment('local', 'testing')) {
        abort(403, 'Demo login switcher is disabled in production environments.');
    }

    $emailMap = [
        'retail' => 'customer@retail.com',
        'wholesale' => 'buyer@boutique.com',
        'seller' => 'seller@nailsfactory.com',
        'admin' => 'admin@b2bhaat.com',
    ];

    if (isset($emailMap[$role])) {
        $user = User::where('email', $emailMap[$role])->first();
        if ($user) {
            Auth::login($user);
            if ($role === 'seller') {
                return redirect('/seller');
            }
            if ($role === 'admin') {
                return redirect('/admin');
            }
        }
    }

    return redirect()->back();
})->name('demo.login');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('logout');
