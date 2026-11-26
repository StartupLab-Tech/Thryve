<?php

namespace App\Ai;

use App\Models\Agent;
use Illuminate\Support\Facades\Cache;

/**
 * The models an agent's Hermes can serve right now, read live (cached for a minute) and reduced to the providers that are
 * signed in. Nothing here is stored and the raw answer never reaches the browser.
 */
class ModelCatalog
{
    public const CACHE_SECONDS = 60;

    public function __construct(private HermesClient $hermes) {}

    /** @return array{live: bool, models: list<array{provider: string, provider_name: string, model: string}>} */
    public function for(Agent $agent): array
    {
        $key = 'model-options:'.$agent->id.':'.sha1($agent->url());
        $models = Cache::get($key);
        if ($models === null) {
            $options = $this->hermes->modelOptions($agent->url(), $agent->outbound_key);
            if ($options === null) {
                return ['live' => false, 'models' => []];
            }
            $models = [];
            foreach ($options['providers'] as $provider) {
                if (($provider['authenticated'] ?? false) !== true || ! is_string($provider['slug'] ?? null)) {
                    continue;
                }
                foreach ((array) ($provider['models'] ?? []) as $model) {
                    if (is_string($model) && $model !== '') {
                        $models[] = ['provider' => $provider['slug'], 'provider_name' => (string) ($provider['name'] ?? $provider['slug']), 'model' => $model];
                    }
                }
            }
            Cache::put($key, $models, self::CACHE_SECONDS);
        }

        return ['live' => true, 'models' => $models];
    }

    /** Is this model listed right now? Null when Hermes could not be read, so callers can tell "gone" from "unknown". */
    public function has(Agent $agent, string $provider, string $model): ?bool
    {
        $catalog = $this->for($agent);
        if (! $catalog['live']) {
            return null;
        }

        return collect($catalog['models'])->contains(fn ($m) => $m['provider'] === $provider && $m['model'] === $model);
    }

    /** The agent used to read the catalog when approving models: the default chatbot. */
    public static function adminAgent(): ?Agent
    {
        return Agent::active()->chat()->where('is_default', true)->first();
    }
}
