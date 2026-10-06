<?php

namespace App\Services\MasterMenu;

use RuntimeException;

/**
 * Penanda bahwa uji-jalan sudah selesai dan transaksinya harus dibatalkan.
 *
 * Dipakai sebagai jalan keluar dari DB::transaction() supaya pembatalannya
 * ditangani Laravel, bukan lewat DB::rollBack() manual yang akan ikut
 * membatalkan transaksi pembungkus di luarnya.
 */
class DryRunCompleted extends RuntimeException {}
