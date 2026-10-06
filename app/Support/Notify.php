<?php

namespace App\Support;

use App\Models\User;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * Satu pintu untuk notifikasi lonceng (tabel `notifications`).
 *
 * Dibaca oleh lonceng topbar Filament dan lonceng header Blade
 * (partials.notification-bell), jadi apa pun aplikasinya, pengguna melihat
 * daftar yang sama. Penerima dipilih lewat izin (mis. requisition.approve,
 * notification.price) supaya pergeseran hak per peran oleh Owner ikut
 * mengubah siapa yang diberi tahu -- tanpa menyentuh kode.
 *
 * Dikirim langsung (sendNow), bukan lewat antrean: sendToDatabase() Filament
 * ShouldQueue, sementara server ini tidak menjalankan queue worker.
 */
class Notify
{
    /**
     * @param  string  $status  info|success|warning|danger
     */
    public static function permission(string $permission, string $title, string $body, ?string $url = null, string $status = 'info', ?int $exceptUserId = null, string $actionLabel = 'Buka'): int
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->permission($permission)
            ->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->get();

        return self::users($recipients, $title, $body, $url, $status, $actionLabel);
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @return int jumlah penerima
     */
    public static function users(Collection $recipients, string $title, string $body, ?string $url = null, string $status = 'info', string $actionLabel = 'Buka'): int
    {
        if ($recipients->isEmpty()) {
            return 0;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->status($status);

        if ($url !== null) {
            $notification->actions([
                NotificationAction::make('buka')->label($actionLabel)->button()->url($url),
            ]);
        }

        LaravelNotification::sendNow($recipients, $notification->toDatabase());

        return $recipients->count();
    }

    /** Rupiah tanpa desimal untuk teks notifikasi. */
    public static function rupiah(float|int|string|null $value): string
    {
        return $value === null ? '-' : 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}
