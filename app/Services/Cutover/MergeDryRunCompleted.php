<?php

namespace App\Services\Cutover;

use RuntimeException;

/** Dilempar di akhir uji-jalan supaya transaksi penggabungan dibatalkan. */
class MergeDryRunCompleted extends RuntimeException {}
