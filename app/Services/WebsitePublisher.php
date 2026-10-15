<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebsitePublish;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bagian C: tombol "Terbitkan ke Website".
 *
 * 3S ONE tidak mengirim data ke website; ia hanya memanggil Deploy Hook
 * hosting. Hosting lalu menjalankan build website, dan skrip build mengambil
 * menu terbaru dari /api/website/menu. Kalau build gagal (mis. 3S ONE sedang
 * tidak bisa diakses), hosting tetap menayangkan versi sebelumnya -- website
 * tidak pernah kosong karena 3S ONE mati.
 */
class WebsitePublisher
{
    /** Jeda minimal antar-terbit yang berhasil: satu build butuh ±1-2 menit. */
    public const COOLDOWN_SECONDS = 120;

    public function __construct(private readonly WebsiteMenuFeed $feed) {}

    public function isConfigured(): bool
    {
        return filled(config('website.deploy_hook_url'));
    }

    /** Sidik isi menu saat ini berbeda dari terbitan terakhir yang berhasil. */
    public function hasUnpublishedChanges(): bool
    {
        $last = WebsitePublish::query()->sent()->latest('id')->value('feed_version');

        return $last !== $this->feed->build()['version'];
    }

    /**
     * Ringkasan untuk kartu "Website" di aplikasi Marketing.
     *
     * @return array{configured: bool, pending: bool, last: ?WebsitePublish, cooldown: int, url: string}
     */
    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'pending' => $this->hasUnpublishedChanges(),
            'last' => $this->lastSent(),
            'cooldown' => $this->cooldownRemaining(),
            'url' => (string) config('website.url'),
        ];
    }

    public function lastSent(): ?WebsitePublish
    {
        return WebsitePublish::query()->sent()->with('user:id,name')->latest('id')->first();
    }

    /** Detik yang masih harus ditunggu sebelum boleh terbit lagi (0 = boleh). */
    public function cooldownRemaining(): int
    {
        $last = WebsitePublish::query()->sent()->latest('id')->value('created_at');

        if ($last === null) {
            return 0;
        }

        return max(0, self::COOLDOWN_SECONDS - (int) now()->diffInSeconds($last, true));
    }

    /**
     * @throws RuntimeException pesan siap tampil untuk pengguna (bahasa Indonesia)
     */
    public function publish(User $user, ?string $ip = null): WebsitePublish
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Website belum tersambung ke 3S ONE (Deploy Hook belum diatur). Hubungi admin sistem.');
        }

        // Dua orang menekan bersamaan: hanya satu yang memicu build.
        $lock = Cache::lock('website-publish', 30);
        if (! $lock->get()) {
            throw new RuntimeException('Website sedang diterbitkan oleh pengguna lain. Tunggu sebentar.');
        }

        try {
            if (($wait = $this->cooldownRemaining()) > 0) {
                throw new RuntimeException('Website baru saja diterbitkan dan masih diperbarui. Coba lagi dalam '.$wait.' detik.');
            }

            $feed = $this->feed->build();
            $error = $this->callDeployHook();

            $publish = WebsitePublish::query()->create([
                'user_id' => $user->id,
                'status' => $error === null ? WebsitePublish::STATUS_SENT : WebsitePublish::STATUS_FAILED,
                'menu_count' => $feed['total'],
                'feed_version' => $feed['version'],
                'error' => $error,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'entity' => 'website',
                'entity_id' => $publish->id,
                'action' => $error === null ? 'website_published' : 'website_publish_failed',
                'message' => $error === null
                    ? 'Menerbitkan '.$feed['total'].' menu ke website'
                    : 'Gagal menerbitkan website: '.$error,
                'ip_address' => $ip,
            ]);

            if ($error !== null) {
                throw new RuntimeException($error.' Coba lagi beberapa saat lagi; bila tetap gagal, hubungi admin sistem.');
            }

            return $publish;
        } finally {
            $lock->release();
        }
    }

    /**
     * Panggil Deploy Hook. Mengembalikan pesan gagal untuk pengguna, atau null
     * bila hosting menerima. Respons mentah hosting hanya masuk log server --
     * URL hook adalah rahasia dan tidak boleh tampil di layar.
     */
    private function callDeployHook(): ?string
    {
        try {
            $response = Http::timeout(15)->acceptJson()->post((string) config('website.deploy_hook_url'));
        } catch (ConnectionException $e) {
            Log::warning('Deploy Hook website tidak bisa dihubungi', ['error' => $e->getMessage()]);

            return 'Hosting website tidak bisa dihubungi.';
        }

        if ($response->failed()) {
            Log::warning('Deploy Hook website menolak permintaan', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            return 'Hosting website menolak permintaan (kode '.$response->status().').';
        }

        return null;
    }
}
