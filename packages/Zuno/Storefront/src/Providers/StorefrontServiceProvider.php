<?php

namespace Zuno\Storefront\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Theme\ViewRenderEventManager;
use Zuno\Storefront\Console\Commands\Setup;

/**
 * The ZUNO storefront: a JSON API for the React site (catalogue, checkout, Razorpay),
 * custom-tee designs shown inside Bagisto's admin order page, and the zuno:setup command.
 */
class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/zuno.php', 'zuno');
    }

    public function boot(): void
    {
        $this->applyRazorpayKeysFromEnv();

        // "web" gives the API sessions (customer login) and CSRF protection
        Route::prefix('api/zuno')
            ->middleware('web')
            ->group(dirname(__DIR__).'/Routes/api.php');

        Route::prefix(config('app.admin_url').'/zuno')
            ->middleware(['web', 'admin'])
            ->group(dirname(__DIR__).'/Routes/admin.php');

        $this->loadViewsFrom(dirname(__DIR__).'/Resources/views', 'zuno');

        // Custom-tee artwork appears under its line on the admin order page
        Event::listen('bagisto.admin.sales.order.list.item.after', function (ViewRenderEventManager $manager) {
            $manager->addTemplate('zuno::admin.order-item-design');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([Setup::class]);
        }
    }

    /**
     * Bagisto's bundled Razorpay method ships with placeholder test keys that would make it look
     * configured. Keys set in the admin panel still win; otherwise they come from the environment,
     * and with no keys the method is unavailable and orders are cash on delivery.
     */
    protected function applyRazorpayKeysFromEnv(): void
    {
        $keyId = config('zuno.razorpay.key_id');
        $secret = config('zuno.razorpay.key_secret');

        config([
            'payment_methods.razorpay.title' => 'Razorpay (UPI, cards, netbanking)',
            'payment_methods.razorpay.active' => (bool) ($keyId && $secret),
            'payment_methods.razorpay.sandbox' => str_starts_with((string) $keyId, 'rzp_test_'),
            'payment_methods.razorpay.test_client_id' => $keyId,
            'payment_methods.razorpay.test_client_secret' => $secret,
            'payment_methods.razorpay.client_id' => $keyId,
            'payment_methods.razorpay.client_secret' => $secret,
            'payment_methods.razorpay.merchant_name' => 'ZUNO',
        ]);
    }
}
