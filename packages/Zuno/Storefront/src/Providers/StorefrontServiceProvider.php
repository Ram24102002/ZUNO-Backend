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
        $this->limitProductTypes();

        // After every package has added its menu entries
        $this->app->booted(fn () => $this->simplifyAdminMenu());

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
     * The admin sidebar shows only what ZUNO uses, with plain names. Hidden pages still open from
     * their address if ever needed (e.g. /admin/settings/channels).
     */
    protected function simplifyAdminMenu(): void
    {
        $hidden = [
            'sales.bookings', 'sales.rma', 'sales.eu_withdrawals',
            'catalog.families',
            'customers.groups', 'customers.gdpr_requests',
            'cms',
            'marketing.promotions', 'marketing.communications', 'marketing.search_seo',
            'appearance',
            'settings.locales', 'settings.currencies', 'settings.exchange_rates', 'settings.inventory_sources',
            'settings.channels', 'settings.roles', 'settings.data_transfer',
        ];

        $renamed = [
            'sales' => 'Orders',
            'sales.orders' => 'All orders',
            'sales.transactions' => 'Payments',
            'catalog' => 'Products',
            'catalog.products' => 'All products',
            'catalog.attributes' => 'Sizes & colours',
            'customers.customers' => 'All customers',
            'marketing' => 'Coupons',
            'reporting' => 'Reports',
            'settings' => 'Admin users & tax',
            'settings.users' => 'Admin users',
            'configuration' => 'Store settings',
        ];

        $menu = collect(config('menu.admin'));
        $couponRoute = $menu->firstWhere('key', 'marketing.promotions.cart_rules')['route'] ?? null;

        $menu = $menu
            ->reject(fn ($item) => collect($hidden)->contains(
                fn ($key) => $item['key'] === $key || str_starts_with($item['key'], $key.'.')
            ))
            ->map(function ($item) use ($renamed, $couponRoute) {
                if (isset($renamed[$item['key']])) {
                    $item['name'] = $renamed[$item['key']];
                }

                // "Coupons" opens the coupon codes (Bagisto's cart rules) directly
                if ($item['key'] === 'marketing' && $couponRoute) {
                    $item['route'] = $couponRoute;
                }

                return $item;
            });

        config(['menu.admin' => $menu->values()->all()]);
    }

    /**
     * "Create Product" in the admin offers only the two kinds of product a clothing shop sells, with
     * plain names. Bagisto's grouped, bundle, downloadable, virtual and booking types are hidden.
     */
    protected function limitProductTypes(): void
    {
        $types = config('product_types');

        config(['product_types' => [
            'configurable' => ['name' => 'Clothing in sizes / colours (configurable)', 'sort' => 1] + $types['configurable'],
            'simple' => ['name' => 'Single item, no options (simple)', 'sort' => 2] + $types['simple'],
        ]]);
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
