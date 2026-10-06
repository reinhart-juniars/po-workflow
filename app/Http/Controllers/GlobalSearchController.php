<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kotak pencarian di bilah atas. Dua pintu ke layanan yang sama:
 * `suggest` untuk daftar singkat saat mengetik, `index` untuk halaman hasil
 * lengkap saat menekan Enter.
 *
 * Penyaringan hak akses seluruhnya milik GlobalSearchService -- controller
 * ini tidak boleh menambah atau melonggarkan filter apa pun.
 */
class GlobalSearchController extends Controller
{
    public function __construct(private readonly GlobalSearchService $search) {}

    /** Daftar singkat untuk dropdown (dipanggil saat mengetik). */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $term = $data['q'] ?? '';

        return response()->json([
            'query' => $term,
            'results' => $this->search->search($request->user(), $term),
        ]);
    }

    /** Halaman hasil lengkap, dikelompokkan per jenis dokumen. */
    public function index(Request $request): View
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim($data['q'] ?? '');
        $results = collect($this->search->search($request->user(), $term))
            ->groupBy('group');

        return view('search.index', [
            'term' => $term,
            'groups' => $results,
            'minLength' => GlobalSearchService::MIN_LENGTH,
        ]);
    }
}
