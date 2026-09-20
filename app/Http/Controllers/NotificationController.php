<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lonceng di header Blade: membaca tabel `notifications` yang sama dengan
 * lonceng Filament, jadi pengguna Owner/Admin/Accounting menerima lonceng
 * yang sama tanpa harus membuka aplikasi Inventory.
 */
class NotificationController extends Controller
{
    /** Buka satu notifikasi: tandai dibaca lalu lompat ke tautannya. */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $url = collect($notification->data['actions'] ?? [])->pluck('url')->filter()->first();

        return redirect()->to($url ?: url()->previous());
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
