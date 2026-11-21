<?php

namespace Zuno\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Admin users with the "Viewer" role can open every admin page but cannot change anything:
 * any request that would save, delete or act (POST, PUT, PATCH, DELETE) is refused, as are the
 * few GET links that act (e.g. "Login as customer"). Signing out still works.
 */
class ViewOnlyAdmin
{
    /** Requests a viewer may still make. */
    private const ALLOWED = [
        'admin.session.destroy',
        'admin.datagrid.look_up',
    ];

    /** GET links that change something or act as someone else. */
    private const BLOCKED_GETS = [
        'admin.customers.customers.login_as_customer',
    ];

    public function handle(Request $request, Closure $next)
    {
        $adminPath = trim(config('app.admin_url'), '/');
        $inAdmin = $request->is($adminPath, $adminPath.'/*');

        if (! $inAdmin || ! self::isViewer() || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        $reading = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($reading && ! $request->routeIs(...self::BLOCKED_GETS)) {
            return $next($request);
        }

        $message = 'You have view-only access and cannot make changes.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        session()->flash('error', $message);

        return redirect()->back(302, [], route('admin.dashboard.index'));
    }

    /** True when the signed-in admin's role is one of `zuno.viewer_roles`. */
    public static function isViewer(): bool
    {
        $role = auth()->guard('admin')->user()?->role?->name;

        return $role !== null && in_array(strtolower($role), array_map('strtolower', config('zuno.viewer_roles')), true);
    }
}
