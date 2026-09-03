<?php

namespace App\Services\MasterMenu;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Akses read-only ke database Master Menu Revamp (SQLite, better-sqlite3).
 *
 * Koneksi didaftarkan on-the-fly supaya file sumber bisa berpindah-pindah
 * (snapshot cutover, salinan staging) tanpa mengubah config/database.php.
 */
class MasterMenuSource
{
    public const CONNECTION = 'master_menu';

    public function __construct(protected ?string $path = null)
    {
        $this->path = $path ?: Config::get('master_menu.database');
    }

    public function path(): string
    {
        if (blank($this->path)) {
            throw new RuntimeException(
                'Path database Master Menu belum diset. Isi MASTER_MENU_DB_PATH di .env atau lewat opsi --db.'
            );
        }

        if (! is_file($this->path)) {
            throw new RuntimeException('File database Master Menu tidak ditemukan: '.$this->path);
        }

        return $this->path;
    }

    public function connection(): ConnectionInterface
    {
        Config::set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => $this->path(),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    public function table(string $table): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table($table);
    }

    public function counts(): Collection
    {
        $tables = [
            'ingredients', 'recipes', 'recipe_items', 'unmatched_items',
            'ingredient_price_history', 'menu_tasks', 'spk', 'spk_orders',
            'orders', 'order_items', 'produksi', 'produksi_rows',
        ];

        return collect($tables)->mapWithKeys(fn (string $t) => [
            $t => (int) $this->table($t)->count(),
        ]);
    }
}
