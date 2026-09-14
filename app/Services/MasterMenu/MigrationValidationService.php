<?php

namespace App\Services\MasterMenu;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeMismatch;
use App\Models\Requisition;
use App\Services\MissingUnitConversionScanner;
use App\Support\Units\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Validasi & pembersihan data pasca migrasi Master Menu -> po-workflow.
 *
 * Setiap pemeriksaan menghasilkan satu temuan bertingkat:
 * - error : data tidak konsisten dan akan salah dihitung (harus dibereskan
 *           sebelum cutover);
 * - warn  : data lengkap tetapi belum siap dipakai (butuh keputusan klien);
 * - info  : angka pembanding, tidak perlu tindakan.
 *
 * Pembersihan (fix) hanya untuk perbaikan yang tidak mungkin salah: spasi
 * ganda pada nama, satuan yang ditulis dengan alias (Gr -> gram), dan baris
 * resep yang benar-benar kosong. Selebihnya dilaporkan, bukan ditebak.
 */
class MigrationValidationService
{
    public const ERROR = 'error';

    public const WARN = 'warn';

    public const INFO = 'info';

    public function __construct(protected MissingUnitConversionScanner $scanner) {}

    /**
     * @return Collection<int, array{level: string, check: string, count: int, detail: string}>
     */
    public function run(?MasterMenuSource $source = null): Collection
    {
        $findings = collect();

        if ($source) {
            $findings = $findings->merge($this->compareWithSource($source));
        }

        return $findings
            ->merge($this->checkItems())
            ->merge($this->checkRecipes())
            ->merge($this->checkLedger())
            ->merge($this->checkProduction())
            ->values();
    }

    /**
     * Terapkan pembersihan yang aman; kembalikan jumlah baris per perbaikan.
     *
     * @return array<string, int>
     */
    public function fix(): array
    {
        return DB::transaction(function () {
            $trimmed = 0;

            foreach (InventoryItem::query()->get(['id', 'name']) as $item) {
                $clean = self::squish($item->name);

                if ($clean !== $item->name) {
                    $item->update(['name' => $clean]);
                    $trimmed++;
                }
            }

            foreach (Recipe::query()->get(['id', 'name', 'name_norm']) as $recipe) {
                $clean = self::squish($recipe->name);

                if ($clean !== $recipe->name) {
                    $recipe->update(['name' => $clean, 'name_norm' => Recipe::normalizeName($clean)]);
                    $trimmed++;
                }
            }

            // Hanya satuan harga bahan yang dikanonkan; satuan baris resep
            // dibiarkan seperti ditulis juru masak ("2 iris") -- pengonversi
            // sudah memahami aliasnya.
            $units = 0;

            foreach ($this->nonCanonicalUnits() as $row) {
                $canonical = Unit::tryFromAlias($row->unit)?->value;

                if ($canonical !== null && $canonical !== $row->unit) {
                    $units += InventoryItem::query()->where('unit', $row->unit)->update(['unit' => $canonical]);
                }
            }

            $emptyLines = RecipeItem::query()
                ->whereNull('inventory_item_id')
                ->whereNull('ref_recipe_id')
                ->where(fn ($q) => $q->whereNull('raw_name')->orWhere('raw_name', ''))
                ->delete();

            return ['nama_dirapikan' => $trimmed, 'satuan_dikanonkan' => $units, 'baris_kosong_dihapus' => $emptyLines];
        });
    }

    /** @return list<array{level: string, check: string, count: int, detail: string}> */
    protected function compareWithSource(MasterMenuSource $source): array
    {
        $sumber = $source->counts();

        $pairs = [
            ['bahan', $sumber['ingredients'], InventoryItem::query()->whereNotNull('source_ingredient_id')->count()],
            ['resep', $sumber['recipes'], Recipe::query()->whereNotNull('source_recipe_id')->count()],
            ['baris resep', $sumber['recipe_items'], RecipeItem::query()->whereHas('recipe', fn ($q) => $q->whereNotNull('source_recipe_id'))->count()],
            ['SPK riwayat', $sumber['spk'], ProductionOrder::query()->whereNotNull('source_spk_id')->count()],
        ];

        $rows = [];

        foreach ($pairs as [$label, $asal, $tujuan]) {
            $rows[] = $this->finding(
                $asal === $tujuan ? self::INFO : self::ERROR,
                "Jumlah {$label} sumber vs tujuan",
                abs($asal - $tujuan),
                "{$asal} di app.db, {$tujuan} di po-workflow".($asal === $tujuan ? ' (sama)' : ' (SELISIH — jalankan ulang inventory:migrate-master-menu)'),
            );
        }

        return $rows;
    }

    /** @return list<array{level: string, check: string, count: int, detail: string}> */
    protected function checkItems(): array
    {
        $rows = [];

        $tanpaSatuan = InventoryItem::query()->whereNotNull('parent_id')->where(fn ($q) => $q->whereNull('unit')->orWhere('unit', ''))->count();
        $rows[] = $this->finding($tanpaSatuan > 0 ? self::ERROR : self::INFO, 'Bahan tanpa satuan', $tanpaSatuan, 'Kebutuhan bahan tidak bisa dihitung tanpa satuan.');

        $tanpaHarga = InventoryItem::query()->whereNotNull('parent_id')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('unit_price')->orWhere('unit_price', '<=', 0))->count();
        $rows[] = $this->finding($tanpaHarga > 0 ? self::WARN : self::INFO, 'Bahan aktif tanpa harga satuan', $tanpaHarga, 'HPP resep yang memakainya dihitung Rp 0.');

        $satuanAsing = InventoryItem::query()->whereNotNull('parent_id')->whereNotNull('unit')->where('unit', '!=', '')
            ->get(['unit'])->pluck('unit')->unique()->reject(fn ($u) => Unit::tryFromAlias($u) !== null);
        $rows[] = $this->finding($satuanAsing->isNotEmpty() ? self::WARN : self::INFO, 'Satuan bahan di luar registri', $satuanAsing->count(),
            $satuanAsing->isNotEmpty() ? 'Contoh: '.$satuanAsing->take(8)->implode(', ').'. Konversi otomatis tidak berlaku; butuh aturan konversi manual.' : 'Semua satuan dikenal.');

        $nonKanonik = collect($this->nonCanonicalUnits())->count();
        $rows[] = $this->finding($nonKanonik > 0 ? self::WARN : self::INFO, 'Satuan bahan ditulis dengan alias (bisa dikanonkan --fix)', $nonKanonik, 'Mis. "Pack" untuk pack; disamakan supaya pencocokan satuan tidak meleset.');

        $spasi = InventoryItem::query()->get(['name'])->filter(fn ($i) => self::squish($i->name) !== $i->name)->count()
            + Recipe::query()->get(['name'])->filter(fn ($r) => self::squish($r->name) !== $r->name)->count();
        $rows[] = $this->finding($spasi > 0 ? self::WARN : self::INFO, 'Nama dengan spasi berlebih (bisa dirapikan --fix)', $spasi, 'Spasi ganda membuat nama yang sama tampak berbeda.');

        $duplikat = InventoryItem::query()->whereNotNull('parent_id')
            ->selectRaw('parent_id, LOWER(TRIM(name)) as nama, COUNT(*) as jumlah')
            ->groupBy('parent_id', DB::raw('LOWER(TRIM(name))'))
            ->having('jumlah', '>', 1)
            ->get();
        $rows[] = $this->finding($duplikat->isNotEmpty() ? self::ERROR : self::INFO, 'Bahan bernama sama dalam satu bucket', $duplikat->count(),
            $duplikat->isNotEmpty() ? 'Contoh: '.$duplikat->take(5)->pluck('nama')->implode(', ').'. Gabungkan lewat Bahan Belum Cocok / hapus salah satunya.' : 'Tidak ada duplikat.');

        return $rows;
    }

    /** @return list<array{level: string, check: string, count: int, detail: string}> */
    protected function checkRecipes(): array
    {
        $rows = [];

        $tanpaBaris = Recipe::query()->where('is_active', true)->whereDoesntHave('items')->count();
        $rows[] = $this->finding($tanpaBaris > 0 ? self::WARN : self::INFO, 'Resep aktif tanpa satu pun bahan', $tanpaBaris, 'HPP-nya Rp 0; nonaktifkan atau lengkapi.');

        $barisKosong = RecipeItem::query()->whereNull('inventory_item_id')->whereNull('ref_recipe_id')
            ->where(fn ($q) => $q->whereNull('raw_name')->orWhere('raw_name', ''))->count();
        $rows[] = $this->finding($barisKosong > 0 ? self::WARN : self::INFO, 'Baris resep kosong (bisa dihapus --fix)', $barisKosong, 'Tidak menunjuk bahan, sub-resep, maupun nama.');

        $belumCocok = RecipeMismatch::query()->where('status', RecipeMismatch::STATUS_OPEN)->count();
        $barisTakTerhubung = RecipeItem::query()->whereNull('inventory_item_id')->whereNull('ref_recipe_id')
            ->whereNotNull('raw_name')->where('raw_name', '!=', '')->count();
        $rows[] = $this->finding($barisTakTerhubung > 0 ? self::WARN : self::INFO, 'Baris resep belum tertaut ke bahan', $barisTakTerhubung,
            "{$belumCocok} nama bahan menunggu keputusan di Bahan Belum Cocok.");

        $selfRef = RecipeItem::query()->whereColumn('ref_recipe_id', 'recipe_id')->count();
        $rows[] = $this->finding($selfRef > 0 ? self::ERROR : self::INFO, 'Resep merujuk dirinya sendiri', $selfRef, 'Perhitungan HPP akan berputar tanpa henti.');

        $konversi = $this->scanner->summary();
        $rows[] = $this->finding($konversi['pasangan'] > 0 ? self::WARN : self::INFO, 'Pasangan satuan tanpa aturan konversi', $konversi['pasangan'],
            "{$konversi['baris']} baris resep tidak terhitung sampai aturannya diisi (Konversi Satuan > Butuh Aturan).");

        $aktif = Recipe::query()->where('is_active', true)->count();
        $terpetakan = Recipe::query()->where('is_active', true)->whereNotNull('product_id')->count();
        $rows[] = $this->finding(self::INFO, 'Resep aktif terpetakan ke produk penjualan', $terpetakan,
            "dari {$aktif} resep aktif; sisanya tidak ikut SPK Produksi dari slot PO.");

        $produkGanda = Recipe::query()->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as jumlah')->groupBy('product_id')->having('jumlah', '>', 1)->count();
        $rows[] = $this->finding($produkGanda > 0 ? self::ERROR : self::INFO, 'Produk dipetakan ke lebih dari satu resep', $produkGanda, 'SPK Produksi tidak tahu resep mana yang dipakai.');

        return $rows;
    }

    /** @return list<array{level: string, check: string, count: int, detail: string}> */
    protected function checkLedger(): array
    {
        $negatif = InventoryMovement::query()
            ->selectRaw('inventory_item_id, SUM(qty) as saldo')
            ->groupBy('inventory_item_id')
            ->having('saldo', '<', -0.0001)
            ->get();

        $yatim = InventoryMovement::query()->whereDoesntHave('item')->count();

        return [
            $this->finding($negatif->isNotEmpty() ? self::WARN : self::INFO, 'Bahan bersaldo ledger negatif', $negatif->count(),
                $negatif->isNotEmpty() ? 'Pemakaian diposting melebihi stok tercatat; periksa Stok Awal form kebutuhan pertama.' : 'Semua saldo >= 0.'),
            $this->finding($yatim > 0 ? self::ERROR : self::INFO, 'Gerakan ledger tanpa bahan', $yatim, 'Baris ledger menunjuk bahan yang sudah tidak ada.'),
        ];
    }

    /** @return list<array{level: string, check: string, count: int, detail: string}> */
    protected function checkProduction(): array
    {
        $tanpaForm = ProductionOrder::query()->where('status', ProductionOrder::STATUS_COMPLETED)
            ->whereNull('source_spk_id')->whereDoesntHave('requisition')->count();

        $formMenggantung = Requisition::query()->where('status', '!=', Requisition::STATUS_CHECKED)
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', [ProductionOrder::STATUS_COMPLETED, ProductionOrder::STATUS_CANCELLED]))->count();

        return [
            $this->finding($tanpaForm > 0 ? self::WARN : self::INFO, 'SPK Produksi selesai tanpa Form Kebutuhan', $tanpaForm, 'Pemakaiannya tidak pernah diposting ke ledger.'),
            $this->finding($formMenggantung > 0 ? self::WARN : self::INFO, 'Form Kebutuhan menggantung pada SPK selesai/batal', $formMenggantung, 'Status form tidak sejalan dengan SPK-nya.'),
        ];
    }

    /** @return list<object{unit: string}> satuan bahan yang dikenal tetapi bukan ejaan kanonik */
    protected function nonCanonicalUnits(): array
    {
        $rows = [];

        foreach (InventoryItem::query()->whereNotNull('unit')->where('unit', '!=', '')->distinct()->pluck('unit') as $unit) {
            $canonical = Unit::tryFromAlias($unit)?->value;

            if ($canonical !== null && $canonical !== $unit) {
                $rows[] = (object) ['unit' => $unit];
            }
        }

        return $rows;
    }

    /** @return array{level: string, check: string, count: int, detail: string} */
    protected function finding(string $level, string $check, int $count, string $detail): array
    {
        return compact('level', 'check', 'count', 'detail');
    }

    public static function squish(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
