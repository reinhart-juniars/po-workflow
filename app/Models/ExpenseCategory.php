<?php

namespace App\Models;

use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    public const MODE_DIRECT_EXPENSE = 'direct_expense';

    public const MODE_INVENTORY_PURCHASE = 'inventory_purchase';

    public const MODE_FIXED_ASSET = 'fixed_asset';

    /**
     * Pengeluaran yang TIDAK masuk Laba Rugi tapi langsung mengurangi Kekayaan di neraca
     * (mis. Biaya Marketing). Kas berkurang tanpa aset pengganti, jadi dicatat sebagai
     * baris pengurang di grup Kekayaan, bukan sebagai biaya maupun aktiva.
     *
     * Sama dengan versi yang sudah berjalan di server (po-workflow lama, 2 Okt 2026);
     * di sini berlaku sejak pengaturan `wealth_reduction.accounting_start` -- sebelum
     * tanggal itu pengeluarannya tetap beban, supaya Laba Rugi yang sudah dilaporkan
     * tidak bergeser.
     */
    public const MODE_WEALTH_REDUCTION = 'wealth_reduction';

    protected $fillable = [
        'name',
        'description',
        'expense_mode',
        'include_hpp',
        'is_active',
    ];

    protected $casts = [
        'include_hpp' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function isDirectExpense(): bool
    {
        return $this->expense_mode === self::MODE_DIRECT_EXPENSE;
    }

    public function isInventoryPurchase(): bool
    {
        return $this->expense_mode === self::MODE_INVENTORY_PURCHASE;
    }

    public function isFixedAsset(): bool
    {
        return $this->expense_mode === self::MODE_FIXED_ASSET;
    }

    public function isWealthReduction(): bool
    {
        return $this->expense_mode === self::MODE_WEALTH_REDUCTION;
    }

    /**
     * Semua mode yang boleh dipilih di master kategori, beserta labelnya.
     *
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return [
            self::MODE_DIRECT_EXPENSE => 'Pengeluaran Langsung',
            self::MODE_INVENTORY_PURCHASE => 'Pembelian Stok',
            self::MODE_FIXED_ASSET => 'Aktiva Tetap',
            self::MODE_WEALTH_REDUCTION => 'Mengurangi Kekayaan (di luar Laba Rugi)',
        ];
    }

    /** Tanggal mulai mode Mengurangi Kekayaan berlaku (Y-m-d). */
    public static function wealthReductionStart(): string
    {
        return (string) app(Settings::class)->get('wealth_reduction.accounting_start');
    }

    public function cashOuts(): HasMany
    {
        return $this->hasMany(CashOut::class);
    }
}
