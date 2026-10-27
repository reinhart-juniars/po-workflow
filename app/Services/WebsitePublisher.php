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
 * menu dari /api/website/menu. Kalau build gagal (mis. 3S ONE sedang tidak
 * bisa diakses), hosting tetap menayangkan versi sebelumnya -- website tidak
 * pernah kosong karena 3S ONE mati.
 *
 * Yang dibaca website adalah SNAPSHOT terbitan terakhir, bukan data yang
 * sedang disunting: marketing melihat pratinjau dulu, dan website hanya
 * berubah setelah pratinjau itu diterbitkan. Build yang terpicu hal lain
 * (commit kode website) tetap memakai snapshot yang sama.
 */
class WebsitePublisher
{
    /** Jeda minimal antar-terbit yang berhasil: satu build butuh ±1-2 menit. */
    public const COOLDOWN_SECONDS = 120;

    public function __construct(
        private readonly WebsiteMenuFeed $feed,
        private readonly WebsiteMenuWarnings $warnings,
    ) {}

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
     * excluded/warnings = menu dicentang yang tidak ikut terbit, per menu dan
     * per jenis masalah (lihat WebsiteMenuWarnings).
     *
     * @return array{configured: bool, pending: bool, last: ?WebsitePublish, cooldown: int, url: string, excluded: list<array<string, mixed>>, warnings: array<string, array<string, mixed>>}
     */
    public function status(): array
    {
        $excluded = $this->warnings->excluded();

        return [
            'configured' => $this->isConfigured(),
            'pending' => $this->hasUnpublishedChanges(),
            'last' => $this->lastSent(),
            'cooldown' => $this->cooldownRemaining(),
            'url' => (string) config('website.url'),
            'excluded' => $excluded,
            'warnings' => $this->warnings->summary($excluded),
        ];
    }

    /** Isi menu terbitan terakhir yang berhasil; null bila belum pernah terbit sejak fitur pratinjau. */
    public function publishedFeed(): ?array
    {
        return WebsitePublish::query()->sent()->whereNotNull('snapshot')->latest('id')->first()?->snapshot;
    }

    /**
     * Perbedaan menu yang akan terbit dengan terbitan terakhir, per menu (id).
     *
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, renamed: list<array<string, mixed>>, moved: list<array<string, mixed>>, photo: list<array<string, mixed>>}
     */
    public function changes(array $draft, ?array $published): array
    {
        $index = function (?array $feed): array {
            $items = [];
            foreach ($feed['brands'] ?? [] as $brand) {
                foreach ($brand['categories'] as $category) {
                    foreach ($category['items'] as $item) {
                        $items[$item['id']] = $item + ['brand' => $brand['label'], 'category' => $category['title']];
                    }
                }
            }

            return $items;
        };

        $now = $index($draft);
        $before = $index($published);
        $changes = ['added' => [], 'removed' => [], 'renamed' => [], 'moved' => [], 'photo' => []];

        foreach ($now as $id => $item) {
            $old = $before[$id] ?? null;

            if ($old === null) {
                $changes['added'][] = $item;

                continue;
            }
            if ($old['name'] !== $item['name']) {
                $changes['renamed'][] = $item + ['old_name' => $old['name']];
            }
            if ($old['category'] !== $item['category'] || $old['brand'] !== $item['brand']) {
                $changes['moved'][] = $item + ['old_category' => $old['category']];
            }
            if (($old['photo']['version'] ?? null) !== ($item['photo']['version'] ?? null)) {
                $changes['photo'][] = $item + ['had_photo' => $old['photo'] !== null];
            }
        }

        foreach (array_diff_key($before, $now) as $item) {
            $changes['removed'][] = $item;
        }

        return $changes;
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
    public function publish(User $user, ?string $ip = null, ?string $previewedVersion = null): WebsitePublish
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

            // Yang diterbitkan harus sama dengan yang terakhir dilihat di pratinjau.
            if ($previewedVersion !== null && $previewedVersion !== $feed['version']) {
                throw new RuntimeException('Menu berubah sejak pratinjau dibuka (oleh Anda atau pengguna lain). Periksa pratinjaunya lagi sebelum menerbitkan.');
            }

            $error = $this->callDeployHook();

            $publish = WebsitePublish::query()->create([
                'user_id' => $user->id,
                'status' => $error === null ? WebsitePublish::STATUS_SENT : WebsitePublish::STATUS_FAILED,
                'menu_count' => $feed['total'],
                'feed_version' => $feed['version'],
                'snapshot' => $error === null ? $feed : null,
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
