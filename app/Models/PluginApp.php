<?php

namespace App\Models;

use App\Plugins\PluginRegistry;
use Database\Factories\PluginAppFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** An outside app the AI can work in. The row is created the first time it is needed; nothing is seeded. */
#[Hidden(['oauth_client_secret', 'gateway_key_hash'])]
class PluginApp extends Model
{
    /** @use HasFactory<PluginAppFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'agent_ids' => 'array', 'tools' => 'array', 'oauth_client_secret' => 'encrypted'];
    }

    /** The settings row of one plugin (made on first use). */
    public static function for(string $slug, ?string $name = null): self
    {
        $app = self::firstOrCreate(['slug' => $slug], ['name' => $name ?? PluginRegistry::find($slug)?->name() ?? $slug]);

        return $app->wasRecentlyCreated ? $app->refresh() : $app; // a new row picks up its database defaults
    }

    public static function github(): self
    {
        return self::for('github');
    }

    /** The company-wide key Hermes uses to call the gateway. It belongs to no single plugin, so it lives on its own row. */
    public static function gateway(): self
    {
        return self::for('gateway', 'Plugin gateway');
    }

    /** Can this person use this plugin with this chatbot right now? It is on for the chatbot and they have a working login. */
    public static function usableBy(User $user, ?int $agentId, string $slug = 'github'): bool
    {
        if (PluginRegistry::find($slug) === null) {
            return false;
        }
        $app = self::for($slug);

        return $app->is_enabled && $app->allowsAgent($agentId) && PluginConnection::active()->where('user_id', $user->id)->where('app_slug', $slug)->exists();
    }

    /** @return list<string> the plugins this person may switch on for this chatbot */
    public static function slugsUsableBy(User $user, ?int $agentId): array
    {
        return array_values(array_filter(array_keys(PluginRegistry::all()), fn (string $slug) => self::usableBy($user, $agentId, $slug)));
    }

    /** May this chatbot use the app? */
    public function allowsAgent(?int $agentId): bool
    {
        return $agentId !== null && in_array($agentId, array_map('intval', (array) $this->agent_ids), true);
    }
}
