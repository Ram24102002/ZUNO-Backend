<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Contact-page messages: the shop saves them (store), the admin reads them under "Messages".
 */
class MessageController extends Controller
{
    /** POST /api/zuno/messages, from the shop's Contact page */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:80',
            'contact' => 'nullable|string|max:120',
            'message' => 'required|string|max:2000',
        ]);

        DB::table('zuno_messages')->insert([
            'name' => $data['name'] ?? null,
            'contact' => $data['contact'] ?? null,
            'message' => $data['message'],
            'customer_id' => auth()->guard('customer')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    /** Admin list, newest first; unread ones are highlighted */
    public function index(): View
    {
        return view('zuno::admin.messages', [
            'messages' => DB::table('zuno_messages')->latest()->paginate(20),
            'unread' => DB::table('zuno_messages')->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(int $id): RedirectResponse
    {
        DB::table('zuno_messages')->where('id', $id)->update(['read_at' => now()]);

        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        DB::table('zuno_messages')->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('zuno_messages')->where('id', $id)->delete();

        return back();
    }
}
