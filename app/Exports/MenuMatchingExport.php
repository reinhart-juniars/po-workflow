<?php

namespace App\Exports;

use App\Filament\Pages\MenuMatching;
use App\Models\Product;
use App\Models\Recipe;
use App\Services\MenuMatchSuggester;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Lembar kerja Pencocokan Menu untuk digarap di Excel lalu diunggah kembali.
 *
 * Sheet pertama: satu baris per produk, diurutkan porsi terjual -- persis
 * daftar di layar, ditambah kolom keputusan yang diisi staf:
 *   - terima_usulan = "ya"  -> tautkan ke usulan yang tertulis di baris itu
 *   - resep_id / nama_resep -> tautkan ke resep tertentu (id lebih pasti;
 *                              nama dicocokkan persis, tanpa peduli huruf besar)
 *   - tanpa_resep = "ya"    -> produk memang tidak dimasak
 * Baris yang ketiganya kosong tidak diubah, jadi berkas boleh diunggah
 * separuh jalan dan dilanjutkan lain hari.
 *
 * Sheet kedua: daftar resep (id + nama) sebagai rujukan VLOOKUP.
 */
class MenuMatchingExport implements WithMultipleSheets
{
    use Exportable;

    public const HEADINGS = [
        'produk_id',
        'nama_produk',
        'harga_jual',
        'aktif',
        'porsi_90_hari',
        'status_sekarang',
        'resep_id',
        'nama_resep',
        'usulan_resep_id',
        'usulan_nama_resep',
        'usulan_skor',
        'terima_usulan',
        'tanpa_resep',
    ];

    public function __construct(protected ?MenuMatchSuggester $suggester = null) {}

    public function sheets(): array
    {
        return [
            new MasterMenuAuditSheet('Pencocokan Menu', $this->rows()),
            new MasterMenuAuditSheet('Daftar Resep', $this->recipes()),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        $suggester = $this->suggester ?? app(MenuMatchSuggester::class);
        $labels = MenuMatching::statusLabels();

        return Product::query()
            ->with('recipe:id,name')
            ->withPorsiTerjual(MenuMatching::HARI_PENJUALAN)
            ->orderByDesc('porsi_terjual')
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($suggester, $labels) {
                $status = MenuMatching::statusOf($product);
                $usulan = $status === MenuMatching::STATUS_BELUM ? $suggester->suggest($product) : null;

                return [
                    'produk_id' => $product->id,
                    'nama_produk' => $product->name,
                    'harga_jual' => (float) $product->base_price,
                    'aktif' => $product->active ? 'ya' : 'tidak',
                    'porsi_90_hari' => (float) $product->porsi_terjual,
                    'status_sekarang' => $labels[$status],
                    'resep_id' => $product->recipe?->id,
                    'nama_resep' => $product->recipe?->name,
                    'usulan_resep_id' => $usulan['recipe']->id ?? null,
                    'usulan_nama_resep' => $usulan['recipe']->name ?? null,
                    'usulan_skor' => $usulan['score'] ?? null,
                    'terima_usulan' => null,
                    'tanpa_resep' => null,
                ];
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function recipes(): Collection
    {
        return Recipe::query()
            ->utama()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Recipe $recipe) => [
                'resep_id' => $recipe->id,
                'nama_resep' => $recipe->name,
                'aktif' => $recipe->is_active ? 'ya' : 'tidak',
            ])
            ->values();
    }
}
