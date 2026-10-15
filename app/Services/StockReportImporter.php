<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Support\Units\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Impor laporan stok gudang ke Item Inventaris, dengan pratinjau.
 *
 * Laporan gudang dan 3S ONE mencatat barang dengan cara berbeda: nama dengan
 * ejaan lain ("Asem Mentah" vs "Asam Mentah") dan satuan lain (laporan per Kg,
 * resep per gram). Mencocokkan otomatis lalu menimpa item akan merusak HPP
 * resep, jadi setiap barang diberi status dan pilihan di pratinjau:
 *
 *   Sama  : nama persis sama        -> bawaan: tautkan, item tidak diubah
 *   Mirip : ada item bernama mirip  -> bawaan: lewati sampai dipilih
 *   Baru  : tidak ada yang mirip    -> bawaan: buat item baru
 *
 * Pilihan per baris: "new", "skip", "link:<id>", "price:<id>" (tautkan +
 * perbarui harga, hanya bila satuannya sama).
 *
 * Sisa stok laporan bisa sekaligus menjadi Saldo Awal Kartu Stok, tetapi hanya
 * untuk item yang BELUM punya catatan Kartu Stok dan satuannya sama dengan
 * laporan. Item yang sudah berjalan dikoreksi lewat Opname Bahan, bukan di sini.
 */
class StockReportImporter
{
    public const ACTION_NEW = 'new';

    public const ACTION_SKIP = 'skip';

    /** Skor minimal (0-100) agar sebuah item disebut "mirip". */
    private const SIMILAR_THRESHOLD = 70;

    /** Skor minimal agar item muncul sebagai saran pilihan. */
    private const CANDIDATE_THRESHOLD = 45;

    private const MAX_CANDIDATES = 5;

    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * @param  list<array{sheet: string, row: int, name: string, unit: ?string, category: string, price: ?float, qty: ?float}>  $rows
     * @return list<array<string, mixed>>
     */
    public function preview(array $rows): array
    {
        $items = InventoryItem::query()->ingredients()->get(['id', 'name', 'unit', 'unit_price', 'category'])
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'price' => $item->unit_price === null ? null : (float) $item->unit_price,
                'key' => self::normalizeName($item->name),
                'tokens' => self::tokens($item->name),
            ])
            ->all();

        $withHistory = InventoryMovement::query()->distinct()->pluck('inventory_item_id')->flip();

        $preview = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = self::normalizeName($row['name']);

            // Barang yang sama di dua sheet (atau dua kali di satu sheet) cukup sekali.
            if (isset($seen[$key])) {
                $preview[$seen[$key]]['duplicates']++;

                continue;
            }

            $unit = $row['unit'] === null ? null : Unit::canonical($row['unit']);
            $candidates = $this->candidates($key, self::tokens($row['name']), $items, $unit, $withHistory);
            $best = $candidates[0] ?? null;

            $status = match (true) {
                $best !== null && $best['score'] >= 100 => 'sama',
                $best !== null && $best['score'] >= self::SIMILAR_THRESHOLD => 'mirip',
                default => 'baru',
            };

            $seen[$key] = count($preview);
            $preview[] = [
                'key' => $row['sheet'].'|'.$row['row'],
                'sheet' => $row['sheet'],
                'row' => $row['row'],
                'name' => $row['name'],
                'unit' => $unit,
                'category' => $row['category'],
                'price' => $row['price'],
                'qty' => $row['qty'],
                'status' => $status,
                'candidates' => $candidates,
                'default' => match ($status) {
                    'sama' => 'link:'.$best['id'],
                    'mirip' => self::ACTION_SKIP,
                    default => self::ACTION_NEW,
                },
                'duplicates' => 0,
            ];
        }

        return $preview;
    }

    /**
     * Simpan pilihan pratinjau dalam satu transaksi.
     *
     * @param  list<array<string, mixed>>  $preview
     * @param  array<string, string>  $choices  kunci baris => aksi
     * @return array{created: int, linked: int, priced: int, skipped: int, stock: int, stock_skipped: array<string, string>}
     */
    public function apply(array $preview, array $choices, bool $withStock, ?CarbonInterface $stockDate, User $user): array
    {
        if ($withStock && ($stockDate === null || $stockDate->isAfter(now()->endOfDay()))) {
            throw new InvalidArgumentException('Tanggal saldo awal wajib diisi dan tidak boleh di masa depan.');
        }

        $previousSource = InventoryItem::$priceChangeSource;
        InventoryItem::$priceChangeSource = 'import';

        try {
            return DB::transaction(fn () => $this->persist($preview, $choices, $withStock, $stockDate, $user));
        } finally {
            InventoryItem::$priceChangeSource = $previousSource;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $preview
     * @param  array<string, string>  $choices
     */
    private function persist(array $preview, array $choices, bool $withStock, ?CarbonInterface $stockDate, User $user): array
    {
        $summary = ['created' => 0, 'linked' => 0, 'priced' => 0, 'skipped' => 0, 'stock' => 0, 'stock_skipped' => []];
        $buckets = InventoryItem::query()->buckets()->orderBy('id')->get(['id', 'category'])->unique('category')->pluck('id', 'category');
        $dateString = $stockDate?->toDateString();

        foreach ($preview as $row) {
            $action = (string) ($choices[$row['key']] ?? $row['default']);

            if ($action === self::ACTION_SKIP) {
                $summary['skipped']++;

                continue;
            }

            if ($action === self::ACTION_NEW) {
                $item = $this->createItem($row, $buckets);
                $summary['created']++;
            } elseif (preg_match('/^(link|price):(\d+)$/', $action, $m)) {
                $item = InventoryItem::query()->ingredients()->find((int) $m[2]);

                // Pilihan hanya boleh menunjuk saran yang ditawarkan untuk baris itu.
                if (! $item || ! in_array($item->id, array_column($row['candidates'], 'id'), true)) {
                    throw new InvalidArgumentException("Pilihan untuk {$row['name']} tidak valid. Baca ulang berkasnya.");
                }

                $summary['linked']++;

                if ($m[1] === 'price' && $row['price'] !== null && $this->sameUnit($row, $item)) {
                    $item->update(['unit_price' => round((float) $row['price'], 4)]);
                    $summary['priced']++;
                }
            } else {
                throw new InvalidArgumentException("Pilihan untuk {$row['name']} tidak dikenal.");
            }

            if (! $withStock || $row['qty'] === null || (float) $row['qty'] <= 0) {
                continue;
            }

            $reason = match (true) {
                ! $this->sameUnit($row, $item) => 'satuan laporan '.($row['unit'] ?? '-').' ≠ satuan item '.$item->unit,
                $this->ledger->hasHistory($item->id) => 'sudah punya Kartu Stok; koreksi lewat Opname Bahan',
                default => null,
            };

            if ($reason !== null) {
                $summary['stock_skipped'][$row['name']] = $reason;

                continue;
            }

            $this->ledger->post($item->id, InventoryMovement::TYPE_OPENING, (float) $row['qty'], $item->unit, $dateString,
                $item->unit_price === null ? null : (float) $item->unit_price,
                ['notes' => 'Saldo awal dari laporan stok gudang '.$stockDate->format('d/m/Y'), 'created_by' => $user->id]);
            $summary['stock']++;
        }

        // entity_id wajib di tabel audit; impor menyentuh banyak item, jadi 0.
        AuditLog::create([
            'user_id' => $user->id,
            'entity' => 'inventory_item',
            'entity_id' => 0,
            'action' => 'stock_report_imported',
            'message' => sprintf('Impor laporan stok gudang: %d item baru, %d ditautkan (%d harga diperbarui), %d dilewati, %d saldo awal.',
                $summary['created'], $summary['linked'], $summary['priced'], $summary['skipped'], $summary['stock']),
        ]);

        return $summary;
    }

    /** @param  Collection<string, int>  $buckets */
    private function createItem(array $row, Collection $buckets): InventoryItem
    {
        // Nama yang sama bisa saja dibuat orang lain sejak pratinjau dibaca.
        $existing = InventoryItem::query()->ingredients()->whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])->first();

        if ($existing) {
            return $existing;
        }

        return InventoryItem::query()->create([
            'name' => $row['name'],
            'unit' => $row['unit'] ?? 'unit',
            'category' => $row['category'],
            'unit_price' => $row['price'] === null ? null : round((float) $row['price'], 4),
            'parent_id' => $buckets[$row['category']] ?? null,
            'is_active' => true,
        ]);
    }

    private function sameUnit(array $row, InventoryItem $item): bool
    {
        return $row['unit'] !== null && $row['unit'] === Unit::canonical($item->unit);
    }

    /**
     * Saran item untuk satu nama: skor 100 = nama sama; selain itu gabungan
     * kemiripan huruf dan kata yang sama, mis. "Ayam Fillet" ~ "Dada Ayam
     * Fillet", "Asem Mentah" ~ "Asam Mentah". Nama pendek yang hanya
     * terkandung ("laos" di "Ayam goreng laos 1/8") tetap di bawah ambang
     * mirip karena huruf-hurufnya jauh berbeda.
     *
     * @param  list<string>  $tokens
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, int>  $withHistory
     * @return list<array{id: int, name: string, unit: string, price: ?float, score: int, same_unit: bool, has_stock: bool}>
     */
    private function candidates(string $key, array $tokens, array $items, ?string $unit, Collection $withHistory): array
    {
        $scored = [];

        foreach ($items as $item) {
            if ($item['key'] === $key) {
                $score = 100;
            } else {
                similar_text($key, $item['key'], $percent);
                $common = count(array_intersect($tokens, $item['tokens']));
                // Kata yang sama dihitung terhadap nama yang lebih pendek: "Ampela"
                // ~ "Ampela Ayam" = 100%. Rata-rata dengan kemiripan huruf supaya
                // nama pendek di dalam nama panjang ("laos") tidak ikut mirip;
                // tanpa satu kata pun yang sama, skornya dibatasi rendah.
                $overlap = $common === 0 ? 0 : 100 * $common / max(1, min(count($tokens), count($item['tokens'])));
                $score = (int) round($common === 0 ? min($percent, 50) : ($percent + $overlap) / 2);
                $score = min($score, 99);
            }

            if ($score >= self::CANDIDATE_THRESHOLD) {
                $scored[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'price' => $item['price'],
                    'score' => $score,
                    'same_unit' => $unit !== null && $unit === Unit::canonical($item['unit']),
                    'has_stock' => $withHistory->has($item['id']),
                ];
            }
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, self::MAX_CANDIDATES);
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** @return list<string> kata bermakna (huruf/angka), tanpa duplikat */
    private static function tokens(string $name): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($words));
    }
}
