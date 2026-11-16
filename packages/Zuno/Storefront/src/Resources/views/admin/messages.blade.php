<x-admin::layouts>
    <x-slot:title>Messages</x-slot>

    @push('styles')
    <style>
        .zm { --card:#fff; --line:#e5e7eb; --text:#111827; --muted:#6b7280; --soft:#f3f4f6; --accent:#2563eb; --new:#eff6ff; --bad:#b91c1c; color:var(--text); }
        .dark .zm { --card:#111827; --line:#1f2937; --text:#f9fafb; --muted:#9ca3af; --soft:#030712; --accent:#60a5fa; --new:#172554; --bad:#fca5a5; }
        .zm-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
        .zm-head h1 { font-size:22px; font-weight:700; }
        .zm-head p { color:var(--muted); font-size:14px; }
        .zm-btn { padding:6px 12px; border-radius:8px; border:1px solid var(--line); background:var(--card); font-weight:600; font-size:13px; cursor:pointer; color:var(--text); }
        .zm-btn:hover { border-color:var(--accent); color:var(--accent); }
        .zm-btn.danger:hover { border-color:var(--bad); color:var(--bad); }
        .zm-list { display:flex; flex-direction:column; gap:12px; }
        .zm-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px 20px; }
        .zm-card.unread { background:var(--new); border-left:4px solid var(--accent); }
        .zm-top { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:baseline; }
        .zm-name { font-weight:700; }
        .zm-meta { color:var(--muted); font-size:13px; }
        .zm-meta a { color:var(--accent); }
        .zm-body { margin-top:10px; white-space:pre-wrap; line-height:1.55; font-size:14px; }
        .zm-actions { display:flex; gap:8px; margin-top:14px; }
        .zm-pill { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px; background:var(--accent); color:#fff; margin-left:8px; }
        .zm-empty { color:var(--muted); text-align:center; padding:60px 0; background:var(--card); border:1px solid var(--line); border-radius:12px; }
    </style>
    @endpush

    <div class="zm">
        <div class="zm-head">
            <div>
                <h1>Messages</h1>
                <p>Feedback sent from the shop's Contact page. {{ $unread }} unread.</p>
            </div>
            @if ($unread)
                <form method="POST" action="{{ route('zuno.admin.messages.read_all') }}">
                    @csrf
                    <button class="zm-btn">Mark all as read</button>
                </form>
            @endif
        </div>

        @if ($messages->isEmpty())
            <div class="zm-empty">No messages yet.</div>
        @else
            <div class="zm-list">
                @foreach ($messages as $m)
                    @php
                        $digits = preg_replace('/\D/', '', (string) $m->contact);
                        $isEmail = str_contains((string) $m->contact, '@');
                        $isPhone = ! $isEmail && strlen($digits) >= 10;
                        $wa = $isPhone ? (strlen($digits) === 10 ? '91'.$digits : $digits) : null;
                    @endphp
                    <div class="zm-card {{ $m->read_at ? '' : 'unread' }}">
                        <div class="zm-top">
                            <div>
                                <span class="zm-name">{{ $m->name ?: 'Anonymous' }}</span>
                                @unless ($m->read_at)<span class="zm-pill">NEW</span>@endunless
                                @if ($m->contact)
                                    <div class="zm-meta">
                                        @if ($isEmail)
                                            <a href="mailto:{{ $m->contact }}">{{ $m->contact }}</a>
                                        @elseif ($isPhone)
                                            <a href="tel:+{{ $wa }}">{{ $m->contact }}</a> ·
                                            <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noreferrer">WhatsApp</a>
                                        @else
                                            {{ $m->contact }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <span class="zm-meta">{{ \Illuminate\Support\Carbon::parse($m->created_at)->format('d M Y, h:i A') }}</span>
                        </div>

                        <div class="zm-body">{{ $m->message }}</div>

                        <div class="zm-actions">
                            @unless ($m->read_at)
                                <form method="POST" action="{{ route('zuno.admin.messages.read', $m->id) }}">
                                    @csrf
                                    <button class="zm-btn">Mark as read</button>
                                </form>
                            @endunless
                            <form method="POST" action="{{ route('zuno.admin.messages.destroy', $m->id) }}" onsubmit="return confirm('Delete this message?')">
                                @csrf
                                @method('DELETE')
                                <button class="zm-btn danger">Delete</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="margin-top:20px">{{ $messages->links() }}</div>
        @endif
    </div>
</x-admin::layouts>
