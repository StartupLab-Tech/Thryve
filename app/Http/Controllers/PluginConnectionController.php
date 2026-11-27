<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Plugins\PluginCallFailed;
use App\Plugins\PluginRegistry;
use App\Plugins\Provider;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** A person connecting, checking and disconnecting their own accounts in outside apps. Never shows or returns a token. */
class PluginConnectionController extends Controller
{
    private const PAGE = '/plugins';

    public function index(Request $request): JsonResponse
    {
        $connections = PluginConnection::where('user_id', $request->user()->id)->get()->keyBy('app_slug');
        $apps = [];
        foreach (PluginRegistry::all() as $slug => $provider) {
            $app = PluginApp::for($slug);
            $connection = $connections->get($slug);
            $apps[] = [
                'slug' => $slug, 'name' => $provider->name(), 'description' => $provider->description(), 'available' => $app->is_enabled && $provider->configured($app),
                'connected' => $connection !== null, 'status' => $connection?->status, 'login' => $connection?->external_login,
                'connected_at' => $connection?->connected_at?->toIso8601String(),
            ];
        }

        return Api::ok(['apps' => $apps]);
    }

    /** Sends the browser to the app's sign-in page. A random `state` and a PKCE challenge are kept in the session for the way back. */
    public function connect(Request $request, string $slug): RedirectResponse
    {
        $provider = $this->provider($slug);
        $app = PluginApp::for($slug);
        if (! $app->is_enabled || ! $provider->configured($app)) {
            return redirect(self::PAGE.'?error=not_available');
        }
        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('plugin_oauth', ['state' => $state, 'verifier' => $verifier, 'user_id' => $request->user()->id, 'slug' => $slug]);

        return redirect()->away($provider->authorizeUrl($app, $state, $verifier));
    }

    /** Plugins that sign in through one shared client share one callback address, so the plugin is the one remembered when the person left. */
    public function callback(Request $request, string $routeSlug): JsonResponse|RedirectResponse
    {
        $saved = $request->session()->pull('plugin_oauth'); // single use
        $slug = is_array($saved) ? (string) ($saved['slug'] ?? '') : $routeSlug;
        $provider = PluginRegistry::find($slug);
        $state = (string) $request->query('state');
        if (! is_array($saved) || ! $provider || $provider->callbackSlug() !== $routeSlug || $saved['user_id'] !== $request->user()->id || $state === '' || ! hash_equals($saved['state'], $state)) {
            return Api::error('invalid_state', 'This sign-in link is not valid any more. Start again from the Plugins page.', 400);
        }
        if ($request->query('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
            return redirect(self::PAGE.'?error=denied');
        }
        $app = PluginApp::for($slug);
        if (! $app->is_enabled || ! $provider->configured($app)) {
            return redirect(self::PAGE.'?error=not_available');
        }
        try {
            $got = $provider->exchange($app, $request->query('code'), $saved['verifier']);
            $login = $provider->identity($got['token']);
        } catch (PluginCallFailed) {
            return redirect(self::PAGE.'?error=failed');
        }
        PluginConnection::updateOrCreate(['user_id' => $request->user()->id, 'app_slug' => $slug],
            ['access_token' => $got['token'], 'refresh_token' => $got['refresh_token'] ?? null, 'token_expires_at' => isset($got['expires_in']) ? now()->addSeconds($got['expires_in']) : null,
                'external_login' => $login, 'scopes' => mb_substr($got['scopes'], 0, 255), 'status' => 'active', 'connected_at' => now(), 'last_used_at' => null]);
        Audit::log("plugin.{$slug}.connected", $request->user(), null, ['login' => $login]);

        return redirect(self::PAGE.'?connected='.$slug);
    }

    public function disconnect(Request $request, string $slug): JsonResponse
    {
        $provider = $this->provider($slug);
        $connection = PluginConnection::where('user_id', $request->user()->id)->where('app_slug', $slug)->first();
        if ($connection) {
            // Plugins that share one grant at the app (Drive, Docs, Sheets) revoke it only when the last of them goes.
            $group = $provider->grantGroup();
            $siblings = $group === null ? [] : array_keys(array_filter(PluginRegistry::all(), fn (Provider $p) => $p->grantGroup() === $group && $p->slug() !== $slug));
            $stillUsed = $siblings !== [] && PluginConnection::where('user_id', $request->user()->id)->whereIn('app_slug', $siblings)->exists();
            if (! $stillUsed) {
                $provider->revoke(PluginApp::for($slug), $connection->access_token);
            }
            $connection->delete();
            Audit::log("plugin.{$slug}.disconnected", $request->user());
        }

        return Api::ok(null);
    }

    private function provider(string $slug): Provider
    {
        return PluginRegistry::find($slug) ?? abort(404);
    }
}
