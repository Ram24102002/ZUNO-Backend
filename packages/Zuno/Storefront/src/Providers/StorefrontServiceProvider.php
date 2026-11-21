<?php

namespace Zuno\Storefront\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;
use Webkul\Core\Http\Middleware\PreventRequestsDuringMaintenance;
use Webkul\Theme\ViewRenderEventManager;
use Zuno\Storefront\Console\Commands\Setup;
use Zuno\Storefront\Http\Controllers\AdminHomeController;
use Zuno\Storefront\Http\Middleware\ViewOnlyAdmin;

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

        // After every package has added its menu entries and routes
        $this->app->booted(function () {
            $this->simplifyAdminMenu();
            $this->replaceAdminDashboard();
        });

        // "web" gives the API sessions (customer login) and CSRF protection
        Route::prefix('api/zuno')
            ->middleware('web')
            ->group(dirname(__DIR__).'/Routes/api.php');

        Route::prefix(config('app.admin_url').'/zuno')
            ->middleware(['web', 'admin'])
            ->group(dirname(__DIR__).'/Routes/admin.php');

        // "Viewer" admins: every admin page is read-only (Bagisto's own and ZUNO's)
        $this->app['router']->pushMiddlewareToGroup('web', ViewOnlyAdmin::class);

        Event::listen('bagisto.admin.layout.content.before', function (ViewRenderEventManager $manager) {
            if (ViewOnlyAdmin::isViewer()) {
                $manager->addTemplate('zuno::admin.view-only-banner');
            }
        });

        $this->loadViewsFrom(dirname(__DIR__).'/Resources/views', 'zuno');
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        // Custom-tee artwork appears under its line on the admin order page
        Event::listen('bagisto.admin.sales.order.list.item.after', function (ViewRenderEventManager $manager) {
            $manager->addTemplate('zuno::admin.order-item-design');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([Setup::class]);
        }
    }

    /**
     * Bagisto's dashboard is replaced by a simpler ZUNO home page at the same address and route name,
     * so the login redirect and the sidebar's first item open it.
     */
    protected function replaceAdminDashboard(): void
    {
        Route::middleware(['web', PreventRequestsDuringMaintenance::class, 'admin', NoCacheMiddleware::class])
            ->prefix(config('app.admin_url'))
            ->get('dashboard', [AdminHomeController::class, 'index'])
            ->name('admin.dashboard.index');

        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * The admin sidebar shows only what ZUNO uses, with plain names. Hidden pages still open from
     * their address if ever needed (e.g. /admin/settings/channels). Invoices, shipments and refunds
     * are made from the order page, so they have no menu entries of their own.
     */
    protected function simplifyAdminMenu(): void
    {
        $hidden = [
            'sales.bookings', 'sales.rma', 'sales.eu_withdrawals',
            'sales.orders', 'sales.shipments', 'sales.invoices', 'sales.refunds', 'sales.transactions',
            'catalog.families',
            'customers.groups', 'customers.gdpr_requests',
            'cms',
            'marketing.promotions', 'marketing.communications', 'marketing.search_seo',
            'appearance',
            'settings.locales', 'settings.currencies', 'settings.exchange_rates', 'settings.inventory_sources',
            'settings.channels', 'settings.roles', 'settings.data_transfer',
        ];

        $renamed = [
            'dashboard' => 'Home',
            'sales' => 'Orders',
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

        // Contact-page messages, right after Orders
        $menu->push([
            'key' => 'messages',
            'name' => 'Messages',
            'route' => 'zuno.admin.messages.index',
            'sort' => 2.5,
            'icon' => 'icon-notification',
        ]);

        // The sentences of the shop's live bar (above the navbar)
        $menu->push([
            'key' => 'live_bar',
            'name' => 'Live bar',
            'route' => 'zuno.admin.announcements.index',
            'sort' => 2.6,
            'icon' => 'icon-cms',
        ]);

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
