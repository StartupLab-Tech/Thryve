<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Agent;
use App\Models\PluginApp;
use App\Plugins\PluginRegistry;
use App\Plugins\Provider;
use App\Plugins\ToolSet;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Setting up outside apps for the whole company. Admins can read; only a Super Admin changes anything. The OAuth secret and the
 * gateway key are never returned after they are saved (the key is shown once, when it is made).
 */
class PluginAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $gateway = PluginApp::gateway();
        $apps = [];
        $shared = [];
        foreach (PluginRegistry::all() as $slug => $provider) {
            $app = PluginApp::for($slug);
            $tools = new ToolSet($provider);
            $owner = $provider->oauthOwner();
            if ($owner !== $slug) {
                $holder = PluginApp::for($owner, (string) config("plugins.shared_oauth.{$owner}.name", $owner));
                $shared[$owner] ??= [
                    'slug' => $owner, 'name' => (string) config("plugins.shared_oauth.{$owner}.name", $owner), 'note' => config("plugins.shared_oauth.{$owner}.note"), 'callback_url' => $provider->callbackUrl(),
                    'oauth_client_id' => $holder->oauth_client_id, 'oauth_secret_set' => filled($holder->oauth_client_secret), 'used_by' => [],
                ];
                $shared[$owner]['used_by'][] = $provider->name();
            }
            $apps[] = [
                'slug' => $slug, 'name' => $provider->name(), 'description' => $provider->description(), 'note' => $provider->adminNote(), 'oauth_shared' => $owner !== $slug ? $owner : null, 'is_enabled' => $app->is_enabled,
                'agent_ids' => array_map('intval', (array) $app->agent_ids),
                'oauth_client_id' => $app->oauth_client_id, 'oauth_secret_set' => filled($app->oauth_client_secret), 'callback_url' => $provider->callbackUrl(),
                'tools' => collect($tools->all())->map(fn ($t) => [
                    'name' => $t['name'], 'kind' => $t['kind'], 'description' => mb_substr((string) $t['description'], 0, 200),
                    'enabled' => $tools->enabled($app, $t['name']), 'default_off' => in_array($t['name'], $provider->defaultOffTools(), true),
                ])->values(),
            ];
        }

        return Api::ok([
            'apps' => $apps,
            'shared_oauth' => array_values($shared),
            'agents' => Agent::active()->chat()->orderBy('name')->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'gateway' => ['key_set' => filled($gateway->gateway_key_hash), 'fingerprint' => $gateway->gateway_key_fingerprint, 'url' => url('/api/plugins/gateway')],
        ]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $provider = $this->provider($slug);
        $tools = new ToolSet($provider);
        $d = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'agent_ids' => ['sometimes', 'array'],
            'agent_ids.*' => ['integer', Rule::exists('agents', 'id')->where(fn ($q) => $q->where('type', Agent::TYPE_CHAT)->whereNull('removed_at'))],
            'tools' => ['sometimes', 'array'],
            'tools.*' => ['boolean'],
        ]);
        foreach (array_keys($d['tools'] ?? []) as $tool) {
            if ($tools->find((string) $tool) === null || $tools->blocked((string) $tool)) {
                return Api::error('validation_failed', 'That tool cannot be switched on or off.', 422, ['tools' => ['That tool cannot be switched on or off.']]);
            }
        }
        $app = PluginApp::for($slug);
        if (array_key_exists('is_enabled', $d)) {
            $app->is_enabled = (bool) $d['is_enabled'];
        }
        if (array_key_exists('agent_ids', $d)) {
            $app->agent_ids = array_values(array_unique(array_map('intval', $d['agent_ids'])));
        }
        if (array_key_exists('tools', $d)) {
            $app->tools = array_merge((array) $app->tools, array_map('boolval', $d['tools']));
        }
        $app->save();
        Audit::log('plugin_app.updated', $request->user(), null, ['app' => $slug, 'is_enabled' => $app->is_enabled, 'agent_ids' => $app->agent_ids, 'tools_changed' => array_keys($d['tools'] ?? [])]);

        return $this->index();
    }

    /** The company's OAuth App for one plugin. The secret is write-only: leaving it empty keeps the one already saved. */
    public function oauth(Request $request, string $slug): JsonResponse
    {
        // A plugin that signs in through a shared client saves it on the shared row; the shared row can also be addressed by its own name.
        $provider = PluginRegistry::find($slug);
        $owner = $provider ? $provider->oauthOwner() : (is_array(config("plugins.shared_oauth.{$slug}")) ? $slug : abort(404));
        $slug = $owner;
        $d = $request->validate([
            'client_id' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_secret' => ['nullable', 'string', 'max:255'],
        ]);
        $app = PluginApp::for($slug, (string) config("plugins.shared_oauth.{$slug}.name", $slug));
        $app->oauth_client_id = $d['client_id'];
        if (filled($d['client_secret'] ?? null)) {
            $app->oauth_client_secret = $d['client_secret'];
        }
        $app->save();
        Audit::log('plugin_app.oauth_saved', $request->user(), null, ['app' => $slug, 'client_id' => $app->oauth_client_id, 'secret_changed' => filled($d['client_secret'] ?? null)]);

        return $this->index();
    }

    /** Makes (or replaces) the one key Hermes uses to call the gateway for every plugin. It is shown here once; only a hash is kept. */
    public function gatewayKey(Request $request): JsonResponse
    {
        $key = 'thryve_plug_'.bin2hex(random_bytes(32));
        $gateway = PluginApp::gateway();
        $gateway->update(['gateway_key_hash' => hash('sha256', $key), 'gateway_key_fingerprint' => substr(hash('sha256', $key), 0, 8)]);
        Audit::log('plugin_app.gateway_key_issued', $request->user(), null, ['fingerprint' => $gateway->gateway_key_fingerprint]);

        return Api::ok(['key' => $key, 'fingerprint' => $gateway->gateway_key_fingerprint, 'gateway_url' => url('/api/plugins/gateway')]);
    }

    private function provider(string $slug): Provider
    {
        return PluginRegistry::find($slug) ?? abort(404);
    }
}
