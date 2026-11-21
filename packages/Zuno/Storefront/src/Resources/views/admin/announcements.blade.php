<x-admin::layouts>
    <x-slot:title>Live bar</x-slot>

    @push('styles')
    <style>
        .zl { --card:#fff; --line:#e5e7eb; --text:#111827; --muted:#6b7280; --accent:#2563eb; --bad:#b91c1c; --bar:#111827; color:var(--text); }
        .dark .zl { --card:#111827; --line:#1f2937; --text:#f9fafb; --muted:#9ca3af; --accent:#60a5fa; --bad:#fca5a5; --bar:#030712; }
        .zl-head { margin-bottom:20px; }
        .zl-head h1 { font-size:22px; font-weight:700; }
        .zl-head p { color:var(--muted); font-size:14px; }
        .zl-preview { background:var(--bar); color:#fff; border-radius:10px; padding:10px 16px; font-size:12px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; text-align:center; margin-bottom:20px; }
        .zl-list { display:flex; flex-direction:column; gap:10px; }
        .zl-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px 16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .zl-card.off { opacity:.55; }
        .zl-card form.zl-edit { display:flex; gap:10px; align-items:center; flex-wrap:wrap; flex:1; min-width:0; }
        .zl-in { border:1px solid var(--line); background:var(--card); color:var(--text); border-radius:8px; padding:7px 10px; font-size:14px; }
        .zl-in.text { flex:2; min-width:220px; }
        .zl-in.link { flex:1; min-width:140px; }
        .zl-check { display:flex; gap:6px; align-items:center; font-size:13px; color:var(--muted); }
        .zl-btn { padding:6px 12px; border-radius:8px; border:1px solid var(--line); background:var(--card); font-weight:600; font-size:13px; cursor:pointer; color:var(--text); }
        .zl-btn:hover { border-color:var(--accent); color:var(--accent); }
        .zl-btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
        .zl-btn.danger:hover { border-color:var(--bad); color:var(--bad); }
        .zl-btn:disabled { opacity:.35; cursor:default; }
        .zl-tools { display:flex; gap:6px; }
        .zl-add { margin-top:24px; background:var(--card); border:1px dashed var(--line); border-radius:12px; padding:16px; }
        .zl-add h2 { font-weight:700; margin-bottom:10px; }
        .zl-err { color:var(--bad); font-size:13px; margin-bottom:12px; }
        .zl-empty { color:var(--muted); text-align:center; padding:40px 0; background:var(--card); border:1px solid var(--line); border-radius:12px; }
    </style>
    @endpush

    <div class="zl">
        <div class="zl-head">
            <h1>Live bar</h1>
            <p>The sentences that rotate in the thin bar above the shop's navbar. Untick "Shown on shop" to hide one without deleting it (saves straight away). Edit the text or link, then click Save. Link is optional, e.g. <code>/design</code>.</p>
        </div>

        @php $shown = $items->where('active', true); @endphp
        <div class="zl-preview">{{ $shown->first()->text ?? 'Nothing shown: the bar is hidden on the shop' }}</div>

        @if ($errors->any())
            <div class="zl-err">{{ $errors->first() }}</div>
        @endif

        @if ($items->isEmpty())
            <div class="zl-empty">No sentences yet. Add one below.</div>
        @else
            <div class="zl-list">
                @foreach ($items as $a)
                    <div class="zl-card {{ $a->active ? '' : 'off' }}">
                        <div class="zl-tools">
                            <form method="POST" action="{{ route('zuno.admin.announcements.move', [$a->id, 'up']) }}">
                                @csrf
                                <button class="zl-btn" title="Move up" @disabled($loop->first)>↑</button>
                            </form>
                            <form method="POST" action="{{ route('zuno.admin.announcements.move', [$a->id, 'down']) }}">
                                @csrf
                                <button class="zl-btn" title="Move down" @disabled($loop->last)>↓</button>
                            </form>
                        </div>

                        <form class="zl-edit" method="POST" action="{{ route('zuno.admin.announcements.update', $a->id) }}">
                            @csrf
                            @method('PUT')
                            <input class="zl-in text" name="text" value="{{ $a->text }}" maxlength="160" required>
                            <input class="zl-in link" name="link" value="{{ $a->link }}" placeholder="Link (optional)" maxlength="255">
                            <label class="zl-check"><input type="checkbox" name="active" value="1" @checked($a->active) onchange="this.form.requestSubmit()"> Shown on shop</label>
                            <button class="zl-btn">Save</button>
                        </form>

                        <form method="POST" action="{{ route('zuno.admin.announcements.destroy', $a->id) }}" onsubmit="return confirm('Delete this sentence?')">
                            @csrf
                            @method('DELETE')
                            <button class="zl-btn danger">Delete</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <form class="zl-add" method="POST" action="{{ route('zuno.admin.announcements.store') }}">
            @csrf
            <h2>Add a sentence</h2>
            <div class="zl-edit" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <input class="zl-in text" name="text" placeholder="e.g. Flat 20% off this weekend" maxlength="160" required>
                <input class="zl-in link" name="link" placeholder="Link (optional)" maxlength="255">
                <input type="hidden" name="active" value="1">
                <button class="zl-btn primary">Add</button>
            </div>
        </form>
    </div>
</x-admin::layouts>
