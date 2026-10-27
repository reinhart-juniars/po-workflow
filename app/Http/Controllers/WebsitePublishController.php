<?php

namespace App\Http\Controllers;

use App\Services\WebsitePublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Bagian C: tombol "Terbitkan ke Website" di halaman Pratinjau Website.
 *
 * feed_version wajib: terbit hanya lewat pratinjau, dan hanya versi yang
 * sedang dilihat (lihat WebsitePublisher::publish).
 */
class WebsitePublishController extends Controller
{
    public function store(Request $request, WebsitePublisher $publisher): RedirectResponse
    {
        $data = $request->validate(
            ['feed_version' => ['required', 'string', 'size:40']],
            ['feed_version.required' => 'Buka Pratinjau Website dulu, lalu terbitkan dari sana.'],
        );

        try {
            $publish = $publisher->publish($request->user(), $request->ip(), $data['feed_version']);
        } catch (RuntimeException $e) {
            return redirect()->route('marketingapp.website.preview')->with('error', $e->getMessage());
        }

        return redirect()->route('marketingapp.website.preview')->with('success', $publish->menu_count.' menu dikirim ke website. Website diperbarui dalam ±1–2 menit.');
    }
}
