<?php

namespace App\Models;

use Database\Factories\ModelChoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** An AI model a Super Admin approved. People only ever see enabled ones. */
class ModelChoice extends Model
{
    /** @use HasFactory<ModelChoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['supports_images' => 'boolean', 'is_enabled' => 'boolean', 'is_default' => 'boolean'];
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
