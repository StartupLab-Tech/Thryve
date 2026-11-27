<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'deleted_at' => 'datetime', 'last_message_at' => 'datetime', 'plugins' => 'array'];
    }

    public function agent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** What the owner sees: not deleted. */
    public function scopeVisible($q)
    {
        return $q->whereNull('deleted_at');
    }
}
