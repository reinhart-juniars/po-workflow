<?php

namespace App\Http\Controllers;

use App\Services\WebsitePublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Bagian C: tombol "Terbitkan ke Website" di aplikasi Marketing. */
class WebsitePublishController extends Controller
{
    public function store(Request $request, WebsitePublisher $publisher): RedirectResponse
    {
        try {
            $publish = $publisher->publish($request->user(), $request->ip());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $publish->menu_count.' menu dikirim ke website. Website diperbarui dalam ±1–2 menit.');
    }
}
