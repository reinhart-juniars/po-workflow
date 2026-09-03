<?php

use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\DB;

/**
 * Runs the given SQL through the real restore entry point and returns the rows
 * it left behind in `restore_probe`.
 */
function restoreSql(string $sql, bool $gzip = false): array
{
    $path = tempnam(sys_get_temp_dir(), 'po_restore_test_');

    if ($gzip) {
        $handle = gzopen($path, 'wb6');
        gzwrite($handle, $sql);
        gzclose($handle);
    } else {
        file_put_contents($path, $sql);
    }

    try {
        app(DatabaseBackupService::class)->restore($path);

        return DB::select('SELECT id, note FROM restore_probe ORDER BY id');
    } finally {
        @unlink($path);
    }
}

it('restores a plain .sql dump', function () {
    $rows = restoreSql(<<<'SQL'
    -- ----------------------------
    -- Table structure: `restore_probe`
    -- ----------------------------
    DROP TABLE IF EXISTS restore_probe;
    CREATE TABLE restore_probe (id INTEGER PRIMARY KEY, note TEXT);
    INSERT INTO restore_probe (id, note) VALUES (1, 'satu');
    INSERT INTO restore_probe (id, note) VALUES (2, 'dua');
    SQL);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->note)->toBe('satu')
        ->and($rows[1]->note)->toBe('dua');
});

it('restores a gzipped dump', function () {
    $rows = restoreSql(<<<'SQL'
    DROP TABLE IF EXISTS restore_probe;
    CREATE TABLE restore_probe (id INTEGER PRIMARY KEY, note TEXT);
    INSERT INTO restore_probe (id, note) VALUES (1, 'terkompresi');
    SQL, gzip: true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->note)->toBe('terkompresi');
});

it('does not split statements on semicolons that sit inside data', function () {
    $rows = restoreSql(<<<'SQL'
    DROP TABLE IF EXISTS restore_probe;
    CREATE TABLE restore_probe (id INTEGER PRIMARY KEY, note TEXT);
    # komentar gaya MySQL; berisi titik koma
    /* komentar blok; juga berisi titik koma */
    INSERT INTO restore_probe (id, note) VALUES
        (1, 'PO-001; PO-002'),
        (2, 'tanda -- bukan komentar di dalam string'),
        (3, 'kutip ganda '' di tengah; masih satu nilai');
    SQL);

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->note)->toBe('PO-001; PO-002')
        ->and($rows[1]->note)->toBe('tanda -- bukan komentar di dalam string')
        ->and($rows[2]->note)->toBe("kutip ganda ' di tengah; masih satu nilai");
});

it('keeps backslash-escaped quotes inside one statement', function () {
    // MySQL's PDO::quote escapes with backslashes, so a value ending in \' must
    // not be read as the end of the literal. SQLite has no backslash escape, so
    // the split itself is asserted on the statements the service yields.
    $path = tempnam(sys_get_temp_dir(), 'po_restore_test_');
    file_put_contents($path, "INSERT INTO t VALUES ('a\\';DROP TABLE t;--');\nSELECT 1;\n");

    $service = app(DatabaseBackupService::class);
    $statements = (fn () => iterator_to_array($this->statements($path)))
        ->call($service);

    @unlink($path);

    expect($statements)->toBe([
        "INSERT INTO t VALUES ('a\\';DROP TABLE t;--')",
        'SELECT 1',
    ]);
});

it('streams the dump instead of loading it into memory', function () {
    // Regression for the fatal "Allowed memory size exhausted" in readGzipFile():
    // the whole decompressed dump used to be concatenated into one string.
    $path = tempnam(sys_get_temp_dir(), 'po_restore_test_').'.sql.gz';
    $handle = gzopen($path, 'wb1');

    gzwrite($handle, "DROP TABLE IF EXISTS restore_probe;\n");
    gzwrite($handle, "CREATE TABLE restore_probe (id INTEGER PRIMARY KEY, note TEXT);\n");

    $filler = str_repeat('x', 900);
    $rowCount = 9000; // ~8.5 MB of uncompressed SQL
    for ($i = 1; $i <= $rowCount; $i++) {
        gzwrite($handle, "INSERT INTO restore_probe (id, note) VALUES ({$i}, '{$filler}');\n");
    }
    gzclose($handle);

    $before = memory_get_peak_usage(true);

    try {
        app(DatabaseBackupService::class)->restore($path);
    } finally {
        @unlink($path);
    }

    $growth = memory_get_peak_usage(true) - $before;

    // Positive control: the restore really did run.
    expect(DB::table('restore_probe')->count())->toBe($rowCount);
    // The old implementation held the full ~8.5 MB dump (plus copies) at once.
    expect($growth)->toBeLessThan(4 * 1024 * 1024);
});

it('refuses a file that is not there', function () {
    app(DatabaseBackupService::class)->restore(sys_get_temp_dir().'/tidak-ada.sql');
})->throws(RuntimeException::class, 'File restore tidak ditemukan');

it('refuses an empty dump', function () {
    restoreSql("-- hanya komentar\n");
})->throws(RuntimeException::class, 'File restore kosong atau tidak terbaca.');
