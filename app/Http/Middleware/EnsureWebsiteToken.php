<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bagian C: hanya skrip build website yang boleh membaca endpoint menu
 * website, dengan token WEBSITE_API_TOKEN di header Authorization: Bearer.
 *
 * Gagal tertutup: token kosong di server berarti semua permintaan ditolak,
 * bukan dibiarkan terbuka.
 */
class EnsureWebsiteToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('website.api_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Token website tidak valid.'], 401);
        }

        return $next($request);
    }
}
