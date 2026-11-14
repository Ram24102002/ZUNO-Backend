<?php

use Illuminate\Support\Facades\Route;
use Zuno\Storefront\Http\Controllers\AccountController;
use Zuno\Storefront\Http\Controllers\CatalogController;
use Zuno\Storefront\Http\Controllers\CheckoutController;
use Zuno\Storefront\Http\Controllers\GoogleLoginController;
use Zuno\Storefront\Http\Controllers\ReviewController;

/*
 * Served under /api/zuno with the "web" middleware: a session cookie keeps customers logged in,
 * and every POST/PUT/DELETE must send the X-XSRF-TOKEN header (the React site's api.js does).
 */

Route::get('health', fn () => ['ok' => true]);

Route::get('config', [CatalogController::class, 'config']);

Route::get('filters', [CatalogController::class, 'filters']);

Route::get('products', [CatalogController::class, 'index']);

Route::get('products/{slug}', [CatalogController::class, 'show']);

Route::get('products/{slug}/reviews', [ReviewController::class, 'index']);

Route::post('products/{slug}/reviews', [ReviewController::class, 'store'])->middleware('throttle:5,1');

Route::post('cart/quote', [CheckoutController::class, 'quote'])->middleware('throttle:30,1');

Route::post('orders', [CheckoutController::class, 'store'])->middleware('throttle:20,1');

Route::post('orders/pay', [CheckoutController::class, 'pay'])->middleware('throttle:20,1');

Route::prefix('account')->controller(AccountController::class)->group(function () {
    Route::get('/', 'show');

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', 'register');
        Route::post('login', 'login');
        Route::post('forgot-password', 'forgotPassword');
        Route::post('reset-password', 'resetPassword');
    });

    Route::post('logout', 'logout');

    // Full-page visits (not fetch calls): out to Google and back
    Route::get('google', [GoogleLoginController::class, 'redirect'])->middleware('throttle:20,1');
    Route::get('google/callback', [GoogleLoginController::class, 'callback'])->middleware('throttle:20,1');

    Route::middleware('auth:customer')->group(function () {
        Route::put('/', 'update');
        Route::post('addresses', 'storeAddress');
        Route::put('addresses/{id}', 'updateAddress')->whereNumber('id');
        Route::delete('addresses/{id}', 'destroyAddress')->whereNumber('id');
        Route::get('orders', 'orders');
        Route::get('orders/{number}', 'order');
        Route::post('orders/{number}/cancel', 'cancel');
    });
});
