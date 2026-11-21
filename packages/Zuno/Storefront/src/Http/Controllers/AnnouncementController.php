<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The live bar above the shop's navbar: the shop reads the sentences (list),
 * the admin adds, edits, reorders, hides and deletes them under "Live bar".
 */
class AnnouncementController extends Controller
{
    /** GET /api/zuno/announcements: the shown sentences, in order */
    public function list(): JsonResponse
    {
        $items = DB::table('zuno_announcements')
            ->where('active', true)
            ->orderBy('sort')->orderBy('id')
            ->get(['text', 'link'])
            ->map(fn ($a) => ['text' => $a->text, 'to' => $a->link ?: null]);

        return response()->json($items);
    }

    public function index(): View
    {
        return view('zuno::admin.announcements', [
            'items' => DB::table('zuno_announcements')->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::table('zuno_announcements')->insert($data + [
            'sort' => (int) DB::table('zuno_announcements')->max('sort') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Sentence added.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        DB::table('zuno_announcements')->where('id', $id)->update($this->validated($request) + ['updated_at' => now()]);

        return back()->with('success', 'Saved.');
    }

    /** Swap with the neighbour above (up) or below (down) */
    public function move(int $id, string $direction): RedirectResponse
    {
        $items = DB::table('zuno_announcements')->orderBy('sort')->orderBy('id')->pluck('id')->all();
        $i = array_search($id, $items);
        $j = $direction === 'up' ? $i - 1 : $i + 1;

        if ($i !== false && isset($items[$j])) {
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
            foreach ($items as $pos => $itemId) {
                DB::table('zuno_announcements')->where('id', $itemId)->update(['sort' => $pos + 1]);
            }
        }

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('zuno_announcements')->where('id', $id)->delete();

        return back()->with('success', 'Sentence deleted.');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'text' => 'required|string|max:160',
            // A page of the shop (/design) or a full web address
            'link' => ['nullable', 'string', 'max:255', 'regex:#^(/|https?://)#'],
        ], ['link.regex' => 'The link must start with / (a shop page) or https://']);

        return [
            'text' => trim($data['text']),
            'link' => $data['link'] ?? null,
            'active' => $request->boolean('active'),
        ];
    }
}
