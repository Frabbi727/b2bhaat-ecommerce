<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CourierWebhookController;
use App\Http\Controllers\Api\V1\TrackingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // 1. Authentication
    Route::prefix('auth')->group(function () {
        Route::post('register/retail', [AuthController::class, 'registerRetail']);
        Route::post('register/wholesale', [AuthController::class, 'registerWholesale']);
        Route::post('register/seller', [AuthController::class, 'registerSeller']);
        Route::post('login', [AuthController::class, 'login']);
    });

    // 2. Product Catalog (with server-side channel price masking)
    Route::prefix('catalog')->group(function () {
        Route::get('products', [CatalogController::class, 'index']);
        Route::get('products/{slug}', [CatalogController::class, 'show']);
    });

    // 3. Public Order Tracking
    Route::prefix('orders')->group(function () {
        Route::get('track', [TrackingController::class, 'track']);
    });

    // 4. Logistics & Courier Webhooks
    Route::prefix('webhooks')->group(function () {
        Route::post('courier', [CourierWebhookController::class, 'handle']);
    });
});
