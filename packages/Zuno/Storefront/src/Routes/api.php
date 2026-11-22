<?php

use Illuminate\Support\Facades\Route;
use Zuno\Storefront\Http\Controllers\AccountController;
use Zuno\Storefront\Http\Controllers\AnnouncementController;
use Zuno\Storefront\Http\Controllers\CatalogController;
use Zuno\Storefront\Http\Controllers\CheckoutController;
use Zuno\Storefront\Http\Controllers\GoogleLoginController;
use Zuno\Storefront\Http\Controllers\MessageController;
use Zuno\Storefront\Http\Controllers\ReviewController;
use Zuno\Storefront\Http\Controllers\SitemapController;

/*
 * Served under /api/zuno with the "web" middleware: a session cookie keeps customers logged in,
 * and every POST/PUT/DELETE must send the X-XSRF-TOKEN header (the React site's api.js does).
 */

Route::get('health', fn () => ['ok' => true]);

// Served to search engines as /sitemap.xml on the shop's domain (vercel.json rewrites it here)
Route::get('sitemap.xml', SitemapController::class);

Route::get('config', [CatalogController::class, 'config']);

Route::get('announcements', [AnnouncementController::class, 'list']);

Route::get('filters', [CatalogController::class, 'filters']);

Route::get('products', [CatalogController::class, 'index']);

Route::get('products/{slug}', [CatalogController::class, 'show']);

Route::get('products/{slug}/reviews', [ReviewController::class, 'index']);

Route::get('testimonials', [ReviewController::class, 'testimonials']);

Route::post('products/{slug}/reviews', [ReviewController::class, 'store'])->middleware('throttle:5,1');

Route::post('orders/track', [AccountController::class, 'track'])->middleware('throttle:10,1');

Route::post('messages', [MessageController::class, 'store'])->middleware('throttle:5,1');

Route::post('cart/quote', [CheckoutController::class, 'quote'])->middleware('throttle:30,1');

Route::post('orders', [CheckoutController::class, 'store'])->middleware('throttle:20,1');

Route::post('orders/pay', [CheckoutController::class, 'pay'])->middleware('throttle:20,1');

Route::prefix('account')->controller(AccountController::class)->group(function () {
    Route::get('/', 'show');

    Route::post('logout', 'logout');

    // The only way in. Full-page visits (not fetch calls): out to Google and back
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
