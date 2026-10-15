<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bagian C: satu kali tombol "Terbitkan ke Website" ditekan. */
class WebsitePublish extends Model
{
    public const STATUS_SENT = 'terkirim';

    public const STATUS_FAILED = 'gagal';

    protected $fillable = ['user_id', 'status', 'menu_count', 'feed_version', 'error'];

    protected $casts = [
        'menu_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }
}
