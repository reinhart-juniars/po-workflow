<?php

namespace App\Console\Commands;

use App\Services\ProfitGuardService;
use Illuminate\Console\Command;

/**
 * Bagian B.2: periksa profit menu keseluruhan terhadap batas atas/bawah.
 * Dijadwalkan harian (routes/console.php) dan juga dipicu otomatis setelah
 * perubahan harga; lonceng hanya keluar saat keadaannya berubah.
 */
class ProfitCheckCommand extends Command
{
    protected $signature = 'profit:check';

    protected $description = 'Periksa profit menu keseluruhan terhadap batas bawah/atas dan kirim lonceng bila berubah keadaan';

    public function handle(ProfitGuardService $guard): int
    {
        $result = $guard->check();
        $agg = $guard->aggregate();

        $this->info(sprintf(
            'Profit keseluruhan %s%% dari %d menu (%d dilewati) -> %s%s',
            number_format($agg['profit_pct'], 2, ',', '.'),
            $agg['count'],
            $agg['skipped'],
            $result['state'],
            $result['announced'] ? ' (berubah, lonceng dikirim)' : ($result['changed'] ? ' (berubah)' : ''),
        ));

        return self::SUCCESS;
    }
}
