<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The admin's first page: today's and this month's sales, orders waiting to be packed, and sizes running
 * out of stock. Replaces Bagisto's dashboard (same address and route name).
 */
class AdminHomeController extends Controller
{
    /** Orders still to be packed and shipped */
    protected const OPEN_STATUSES = ['pending', 'pending_payment', 'processing'];

    /** Stock at or below this is "running low" */
    protected const LOW_STOCK = 5;

    public function index(): View
    {
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();

        $openOrders = DB::table('orders')
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('created_at')
            ->limit(10)
            ->get(['id', 'increment_id', 'status', 'customer_first_name', 'customer_last_name', 'base_grand_total', 'total_qty_ordered', 'created_at']);

        return view('zuno::admin.home', [
            'today' => $this->sales($today),
            'month' => $this->sales($monthStart),
            'openCount' => DB::table('orders')->whereIn('status', self::OPEN_STATUSES)->count(),
            'openOrders' => $openOrders,
            'lowStock' => $this->lowStock(),
            'lowStockLimit' => self::LOW_STOCK,
            'week' => $this->lastSevenDays(),
            'monthName' => $monthStart->format('F'),
        ]);
    }

    /** Orders placed since $from, and their total (cancelled ones left out) */
    protected function sales(Carbon $from): array
    {
        $row = DB::table('orders')
            ->where('created_at', '>=', $from)
            ->where('status', '!=', 'canceled')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(base_grand_total - base_grand_total_refunded), 0) as total')
            ->first();

        return ['orders' => (int) $row->orders, 'total' => (float) $row->total];
    }

    /** Sales for each of the last 7 days, oldest first */
    protected function lastSevenDays(): array
    {
        $from = Carbon::today()->subDays(6);

        $totals = DB::table('orders')
            ->where('created_at', '>=', $from)
            ->where('status', '!=', 'canceled')
            ->selectRaw('DATE(created_at) as day, SUM(base_grand_total - base_grand_total_refunded) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, 6))->map(function ($i) use ($from, $totals) {
            $day = $from->copy()->addDays($i);

            return ['label' => $day->format('D'), 'date' => $day->format('d M'), 'total' => (float) ($totals[$day->toDateString()] ?? 0)];
        })->all();
    }

    /** Sizes / colours (or single items) with little stock left, lowest first */
    protected function lowStock()
    {
        return DB::table('products as p')
            ->leftJoin('product_inventories as i', 'i.product_id', '=', 'p.id')
            ->join('product_flat as f', function ($join) {
                $join->on('f.product_id', '=', 'p.id')
                    ->where('f.channel', core()->getDefaultChannelCode())
                    ->where('f.locale', app()->getLocale());
            })
            ->where('p.type', 'simple')
            ->where('f.status', 1)
            ->groupBy('p.id', 'p.parent_id', 'p.sku', 'f.name')
            ->havingRaw('COALESCE(SUM(i.qty), 0) <= ?', [self::LOW_STOCK])
            ->orderByRaw('COALESCE(SUM(i.qty), 0)')
            ->limit(10)
            ->get(['p.id', 'p.parent_id', DB::raw('COALESCE(f.name, p.sku) as name'), DB::raw('COALESCE(SUM(i.qty), 0) as qty')]);
    }
}
