<?php

namespace App\Models;

use Database\Factories\PluginConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's own login to an outside app. The token is encrypted and never leaves the server. */
#[Hidden(['access_token', 'refresh_token'])]
class PluginConnection extends Model
{
    /** @use HasFactory<PluginConnectionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'token_expires_at' => 'datetime', 'connected_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
