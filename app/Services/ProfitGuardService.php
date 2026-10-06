<?php

namespace App\Services;

use App\Filament\Pages\Dashboard;
use App\Models\AppSetting;
use App\Models\Product;
use App\Support\Notify;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Bagian B.2 -- Notifikasi Batas Atas & Bawah Profit Menu.
 *
 * Profit keseluruhan menu = (Σ harga jual − Σ biaya) / Σ biaya atas semua
 * menu aktif yang punya resep dan biayanya terhitung; "biaya" = HPP bahan +
 * OHC, sama dengan yang dipakai Analisa HPP, dan harga jual = harga jual
 * produk di PO Workflow (harga yang benar-benar ditagihkan), bukan target
 * di resep. Diukur terhadap batas bawah/atas di Pengaturan Inventory
 * (profit.lower_bound_pct / profit.upper_bound_pct).
 *
 * Lonceng dikirim hanya saat KEADAAN berubah (normal -> di bawah batas,
 * di bawah -> normal, dst.), bukan setiap kali diperiksa; keadaan terakhir
 * disimpan di app_settings (kunci internal, tidak tampil di Pengaturan).
 */
class ProfitGuardService
{
    public const STATE_KEY = 'profit.alert_state';

    public const CACHE_KEY = 'profit.aggregate';

    public function __construct(protected RecipeCostService $costs) {}

    /**
     * @return array{count: int, skipped: int, revenue: float, cost: float, profit: float, profit_pct: float, lower: float, upper: float, state: string, worst: list<array{name: string, sku: ?string, price: float, cost: float, profit_pct: float}>}
     */
    public function aggregate(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => $this->compute());
    }

    /** @return array<string, mixed> */
    protected function compute(): array
    {
        $settings = app(Settings::class);
        $revenue = 0.0;
        $cost = 0.0;
        $count = 0;
        $skipped = 0;
        $rows = [];

        $products = Product::query()
            ->where('active', true)
            ->whereNotNull('recipe_id')
            ->with('recipe')
            ->get();

        foreach ($products as $product) {
            $recipe = $product->recipe;

            if (! $recipe || ! $recipe->is_active) {
                $skipped++;

                continue;
            }

            $c = $this->costs->cost($recipe);
            $price = (float) $product->base_price > 0 ? (float) $product->base_price : (float) $c['harga_jual_dipakai'];
            $biaya = (float) $c['total_biaya'];

            // Hanya resep yang perhitungannya bersih: bahan belum tertaut atau
            // satuan belum terkonversi membuat biaya nol atau melambung, dan
            // satu resep rusak bisa menyeret angka keseluruhan.
            if ($biaya <= 0 || $price <= 0 || $c['has_unmatched'] || $c['issues'] !== []) {
                $skipped++;

                continue;
            }

            $count++;
            $revenue += $price;
            $cost += $biaya;
            $rows[] = [
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => $price,
                'cost' => $biaya,
                'profit_pct' => round(($price - $biaya) / $biaya * 100, 1),
            ];
        }

        usort($rows, fn ($a, $b) => $a['profit_pct'] <=> $b['profit_pct']);

        $profitPct = $cost > 0 ? round(($revenue - $cost) / $cost * 100, 2) : 0.0;
        $lower = (float) $settings->get('profit.lower_bound_pct');
        $upper = (float) $settings->get('profit.upper_bound_pct');

        return [
            'count' => $count,
            'skipped' => $skipped,
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'profit' => round($revenue - $cost, 2),
            'profit_pct' => $profitPct,
            'lower' => $lower,
            'upper' => $upper,
            // 'none' = belum ada resep yang bersih untuk dihitung; tidak dilaporkan.
            'state' => $count === 0 ? 'none' : ($profitPct < $lower ? 'below' : ($profitPct > $upper ? 'above' : 'ok')),
            'worst' => array_slice($rows, 0, 5),
        ];
    }

    /**
     * Periksa sekarang; kirim lonceng ke pemegang izin notification.profit
     * bila keadaannya berubah dari pemeriksaan sebelumnya.
     *
     * @return array{state: string, previous: ?string, changed: bool, announced: bool, profit_pct: float}
     */
    public function check(?int $actorId = null): array
    {
        $agg = $this->aggregate(fresh: true);
        $previous = AppSetting::query()->where('key', self::STATE_KEY)->first()?->value;
        $changed = $previous !== $agg['state'];
        $announced = false;

        if ($changed) {
            AppSetting::query()->updateOrCreate(['key' => self::STATE_KEY], ['value' => $agg['state'], 'updated_by' => $actorId]);

            // Lonceng hanya untuk perubahan yang bermakna: masuk/keluar batas,
            // atau kembali normal. Pemeriksaan pertama yang normal, dan keadaan
            // 'none' (belum ada resep bersih), tidak dilaporkan.
            if ($agg['state'] !== 'none' && ($previous !== null && $previous !== 'none' || $agg['state'] !== 'ok')) {
                $this->announce($agg, $previous);
                $announced = true;
            }
        }

        return ['state' => $agg['state'], 'previous' => $previous, 'changed' => $changed, 'announced' => $announced, 'profit_pct' => $agg['profit_pct']];
    }

    /** @param  array<string, mixed>  $agg */
    protected function announce(array $agg, ?string $previous): void
    {
        $pct = number_format($agg['profit_pct'], 1, ',', '.').'%';
        $rentang = number_format($agg['lower'], 0, ',', '.').'–'.number_format($agg['upper'], 0, ',', '.').'%';

        [$title, $body, $status] = match ($agg['state']) {
            'below' => [
                'Profit menu keseluruhan di BAWAH batas: '.$pct,
                'Batas bawah '.number_format($agg['lower'], 0, ',', '.').'% dari '.$agg['count'].' menu aktif. Terendah: '
                    .collect($agg['worst'])->take(3)->map(fn ($r) => $r['name'].' ('.number_format($r['profit_pct'], 0, ',', '.').'%)')->implode(', ').'.',
                'danger',
            ],
            'above' => [
                'Profit menu keseluruhan di ATAS batas: '.$pct,
                'Batas atas '.number_format($agg['upper'], 0, ',', '.').'% dari '.$agg['count'].' menu aktif. Periksa apakah harga bahan atau harga jual perlu disesuaikan.',
                'warning',
            ],
            default => [
                'Profit menu keseluruhan kembali normal: '.$pct,
                'Berada di rentang '.$rentang.' ('.$agg['count'].' menu aktif).',
                'success',
            ],
        };

        Notify::permission('notification.profit', $title, $body, Dashboard::getUrl(), $status, null, 'Buka dashboard');
    }
}
