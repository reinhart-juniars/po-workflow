<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menu sisa V2 yang tidak dipegang tim menu (tanpa resep) dinonaktifkan
 * setelah 90 hari tidak dipakai (revisi 9 Nov 2026).
 *
 * "Dipakai" = muncul di PO (tanggal kirim atau tanggal dibuat) atau di sales
 * actual dalam 90 hari terakhir. Menu yang dibuat atau diubah dalam 90 hari
 * terakhir juga dibiarkan: tim menu yang mengaktifkan lagi menu lama tidak
 * melihatnya mati keesokan paginya. Menu bertaut resep tidak disentuh --
 * status aktifnya ikut resep (MasterMenuSync).
 */
class DeactivateUnusedMenusCommand extends Command
{
    public const IDLE_DAYS = 90;

    protected $signature = 'menu:deactivate-unused {--dry-run : Tampilkan menu yang akan dinonaktifkan tanpa mengubahnya}';

    protected $description = 'Nonaktifkan menu tanpa resep yang 90 hari tidak dipakai di PO maupun sales actual';

    public function handle(): int
    {
        $since = Carbon::now()->subDays(self::IDLE_DAYS);
        $menus = $this->idleMenus($since);

        if ($menus->isEmpty()) {
            $this->info('Tidak ada menu yang perlu dinonaktifkan.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['ID', 'Menu', 'Dibuat'], $menus->map(fn (Product $p) => [$p->id, $p->name, $p->created_at?->format('d M Y')])->all());
            $this->warn($menus->count().' menu akan dinonaktifkan (uji-jalan, belum diubah).');

            return self::SUCCESS;
        }

        // Lewat model, bukan update massal: audit log dan peringatan menu
        // website (WebsiteMenuWarnings) ikut berjalan.
        $menus->each(fn (Product $product) => $product->update(['active' => false]));

        $this->info($menus->count().' menu tanpa resep dinonaktifkan karena '.self::IDLE_DAYS.' hari tidak dipakai.');

        return self::SUCCESS;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Product> */
    public function idleMenus(Carbon $since)
    {
        $sinceDate = $since->toDateString();

        $usedInPo = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereNotNull('purchase_order_items.product_id')
            ->where(fn ($q) => $q->where('purchase_orders.delivery_date', '>=', $sinceDate)
                ->orWhere('purchase_orders.created_at', '>=', $since))
            ->select('purchase_order_items.product_id');

        $usedInSales = DB::table('sales_actual_items')
            ->join('sales_actuals', 'sales_actuals.id', '=', 'sales_actual_items.sales_actual_id')
            ->whereNotNull('sales_actual_items.product_id')
            ->where('sales_actuals.sales_date', '>=', $sinceDate)
            ->select('sales_actual_items.product_id');

        return Product::query()
            ->where('active', true)
            ->whereNull('recipe_id')
            ->where('created_at', '<', $since)
            ->where('updated_at', '<', $since)
            ->whereNotIn('id', $usedInPo)
            ->whereNotIn('id', $usedInSales)
            ->orderBy('name')
            ->get();
    }
}
