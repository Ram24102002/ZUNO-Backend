@php
    $money = fn ($v) => core()->formatBasePrice($v);
    $weekMax = max(array_column($week, 'total')) ?: 1;
    $statusNames = ['pending' => 'New', 'pending_payment' => 'Awaiting payment', 'processing' => 'Paid, to ship'];
@endphp

<x-admin::layouts>
    <x-slot:title>Home</x-slot>

    @push('styles')
    <style>
        .zh { --card:#fff; --line:#e5e7eb; --text:#111827; --muted:#6b7280; --soft:#f3f4f6; --accent:#2563eb; --warn:#b45309; --warn-bg:#fef3c7; --bad:#b91c1c; --bad-bg:#fee2e2; color:var(--text); }
        .dark .zh { --card:#111827; --line:#1f2937; --text:#f9fafb; --muted:#9ca3af; --soft:#030712; --accent:#60a5fa; --warn:#fbbf24; --warn-bg:#422006; --bad:#fca5a5; --bad-bg:#450a0a; }
        .zh-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
        .zh-head h1 { font-size:22px; font-weight:700; }
        .zh-head p { color:var(--muted); font-size:14px; }
        .zh-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .zh-btn { padding:8px 14px; border-radius:8px; border:1px solid var(--line); background:var(--card); font-weight:600; font-size:14px; }
        .zh-btn:hover { border-color:var(--accent); color:var(--accent); }
        .zh-btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
        .zh-btn.primary:hover { opacity:.9; color:#fff; }
        .zh-tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:16px; }
        .zh-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; }
        .zh-tile .label { color:var(--muted); font-size:14px; font-weight:600; }
        .zh-tile .value { font-size:28px; font-weight:700; margin-top:6px; font-variant-numeric:tabular-nums; }
        .zh-tile .sub { color:var(--muted); font-size:13px; margin-top:2px; }
        .zh-tile.alert .value { color:var(--warn); }
        a.zh-tile:hover { border-color:var(--accent); }
        .zh-grid { display:grid; grid-template-columns:3fr 2fr; gap:16px; }
        @media (max-width: 1100px) { .zh-grid { grid-template-columns:1fr; } }
        .zh-card h2 { font-size:16px; font-weight:700; margin-bottom:4px; display:flex; justify-content:space-between; align-items:baseline; }
        .zh-card h2 a { font-size:13px; font-weight:600; color:var(--accent); }
        .zh-card .hint { color:var(--muted); font-size:13px; margin-bottom:12px; }
        .zh-row { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:10px 8px; border-top:1px solid var(--line); border-radius:6px; }
        a.zh-row:hover { background:var(--soft); }
        .zh-row .main { font-weight:600; font-size:14px; }
        .zh-row .meta { color:var(--muted); font-size:13px; }
        .zh-row .right { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .zh-pill { display:inline-block; font-size:12px; font-weight:600; padding:2px 8px; border-radius:999px; background:var(--soft); color:var(--muted); }
        .zh-pill.warn { background:var(--warn-bg); color:var(--warn); }
        .zh-pill.bad { background:var(--bad-bg); color:var(--bad); }
        .zh-empty { color:var(--muted); font-size:14px; padding:16px 0; border-top:1px solid var(--line); }
        .zh-bars { display:flex; align-items:flex-end; gap:10px; height:140px; margin-top:12px; }
        .zh-bar { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; gap:6px; }
        .zh-bar span.fill { width:100%; max-width:36px; background:var(--accent); border-radius:4px 4px 0 0; min-height:2px; }
        .zh-bar span.day { font-size:12px; color:var(--muted); }
        .zh-side { display:flex; flex-direction:column; gap:16px; }
    </style>
    @endpush

    <div class="zh">
        <div class="zh-head">
            <div>
                <h1>Hello, {{ auth()->guard('admin')->user()->name }}</h1>
                <p>{{ now()->format('l, d F Y') }}</p>
            </div>

            <div class="zh-actions">
                <a class="zh-btn primary" href="{{ route('admin.catalog.products.index') }}">+ Add product</a>
                <a class="zh-btn" href="{{ route('admin.marketing.promotions.cart_rules.create') }}">+ New coupon</a>
                <a class="zh-btn" href="{{ route('admin.reporting.sales.index') }}">Full reports</a>
            </div>
        </div>

        <div class="zh-tiles">
            <div class="zh-card zh-tile">
                <div class="label">Sales today</div>
                <div class="value">{{ $money($today['total']) }}</div>
                <div class="sub">{{ $today['orders'] }} {{ Str::plural('order', $today['orders']) }}</div>
            </div>

            <div class="zh-card zh-tile">
                <div class="label">Sales in {{ $monthName }}</div>
                <div class="value">{{ $money($month['total']) }}</div>
                <div class="sub">{{ $month['orders'] }} {{ Str::plural('order', $month['orders']) }}</div>
            </div>

            <a class="zh-card zh-tile {{ $openCount ? 'alert' : '' }}" href="{{ route('admin.sales.orders.index') }}">
                <div class="label">Orders to pack &amp; ship</div>
                <div class="value">{{ $openCount }}</div>
                <div class="sub">{{ $openCount ? 'Open orders →' : 'All caught up' }}</div>
            </a>

            <a class="zh-card zh-tile {{ $lowStock->count() ? 'alert' : '' }}" href="#zh-stock">
                <div class="label">Running low on stock</div>
                <div class="value">{{ $lowStock->count() }}{{ $lowStock->count() >= 10 ? '+' : '' }}</div>
                <div class="sub">{{ $lowStockLimit }} or fewer left</div>
            </a>
        </div>

        <div class="zh-grid">
            <div class="zh-card">
                <h2>
                    Orders to pack &amp; ship
                    <a href="{{ route('admin.sales.orders.index') }}">All orders →</a>
                </h2>
                <p class="hint">Oldest first. Open an order to create the invoice and shipment.</p>

                @forelse ($openOrders as $order)
                    <a class="zh-row" href="{{ route('admin.sales.orders.view', $order->id) }}">
                        <div>
                            <div class="main">#{{ $order->increment_id }} · {{ trim($order->customer_first_name.' '.$order->customer_last_name) ?: 'Guest' }}</div>
                            <div class="meta">
                                {{ Carbon\Carbon::parse($order->created_at)->diffForHumans() }} ·
                                {{ (int) $order->total_qty_ordered }} {{ Str::plural('item', (int) $order->total_qty_ordered) }}
                            </div>
                        </div>
                        <div class="right">
                            <div class="main">{{ $money($order->base_grand_total) }}</div>
                            <span class="zh-pill {{ $order->status === 'processing' ? 'warn' : '' }}">{{ $statusNames[$order->status] ?? ucfirst($order->status) }}</span>
                        </div>
                    </a>
                @empty
                    <p class="zh-empty">No open orders. Nice work.</p>
                @endforelse
            </div>

            <div class="zh-side">
                <div class="zh-card">
                    <h2>
                        Last 7 days
                        <a href="{{ route('admin.reporting.sales.index') }}">Reports →</a>
                    </h2>
                    <p class="hint">{{ $money(array_sum(array_column($week, 'total'))) }} in sales</p>

                    <div class="zh-bars">
                        @foreach ($week as $day)
                            <div class="zh-bar" title="{{ $day['date'] }}: {{ $money($day['total']) }}">
                                <span class="fill" style="height: {{ round($day['total'] / $weekMax * 100) }}%"></span>
                                <span class="day">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="zh-card" id="zh-stock">
                    <h2>
                        Running low
                        <a href="{{ route('admin.catalog.products.index') }}">Products →</a>
                    </h2>
                    <p class="hint">Click one to add stock.</p>

                    @forelse ($lowStock as $item)
                        <a class="zh-row" href="{{ route('admin.catalog.products.edit', $item->parent_id ?: $item->id) }}">
                            <div class="main">{{ $item->name }}</div>
                            <span class="zh-pill {{ $item->qty <= 0 ? 'bad' : 'warn' }}">{{ $item->qty <= 0 ? 'Sold out' : (int) $item->qty.' left' }}</span>
                        </a>
                    @empty
                        <p class="zh-empty">Everything is well stocked.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-admin::layouts>
