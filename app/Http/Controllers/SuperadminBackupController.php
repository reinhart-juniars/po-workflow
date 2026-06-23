<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SuperadminBackupController extends Controller
{
    public function index()
    {
        return view('superadmin.backup', [
            'database' => DB::connection()->getDatabaseName(),
            'driver' => DB::connection()->getDriverName(),
            'environment' => app()->environment(),
            'restoreEnabled' => !app()->environment('production'),
        ]);
    }

    public function download(DatabaseBackupService $service): BinaryFileResponse
    {
        $filename = sprintf(
            '%s_backup_%s.sql.gz',
            DB::connection()->getDatabaseName(),
            now()->format('Ymd_His')
        );

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $tempPath = tempnam(sys_get_temp_dir(), 'po_backup_') . '.sql.gz';

        $stream = gzopen($tempPath, 'wb6');
        if ($stream === false) {
            @unlink($tempPath);
            throw new \RuntimeException('Gagal membuka file backup sementara.');
        }

        try {
            $service->dump(function (string $chunk) use ($stream): void {
                gzwrite($stream, $chunk);
            });
        } catch (Throwable $e) {
            gzclose($stream);
            @unlink($tempPath);
            Log::error('Backup database gagal', ['message' => $e->getMessage()]);
            abort(500, 'Backup gagal: ' . $e->getMessage());
        }

        gzclose($stream);

        return response()
            ->download($tempPath, $filename, [
                'Content-Type' => 'application/gzip',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ])
            ->deleteFileAfterSend(true);
    }

    public function restore(Request $request, DatabaseBackupService $service): RedirectResponse
    {
        if (app()->environment('production')) {
            return redirect()
                ->route('superadmin.backup.index')
                ->with('error', 'Restore tidak diperbolehkan di environment production.');
        }

        $request->validate([
            'sql_file' => ['required', 'file', 'max:512000'],
            'confirm' => ['required', 'in:RESTORE'],
        ], [
            'sql_file.max' => 'Maksimal ukuran file 500 MB.',
            'confirm.in' => 'Ketik RESTORE untuk konfirmasi.',
        ]);

        $file = $request->file('sql_file');
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = ['sql', 'gz'];

        if (!in_array($extension, $allowed, true)) {
            return back()->with('error', 'Format file harus .sql atau .sql.gz');
        }

        @set_time_limit(0);

        try {
            $service->restore($file->getRealPath());
        } catch (Throwable $e) {
            Log::error('Restore database gagal', [
                'message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('superadmin.backup.index')
                ->with('error', 'Restore gagal: ' . $e->getMessage());
        }

        return redirect()
            ->route('superadmin.backup.index')
            ->with('success', 'Restore berhasil. Database telah dikembalikan dari file backup.');
    }
}
