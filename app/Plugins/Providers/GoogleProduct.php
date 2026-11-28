<?php

namespace App\Plugins\Providers;

use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Plugins\AbstractProvider;
use App\Plugins\AsksPermission;
use App\Plugins\ListsTools;
use App\Plugins\McpClient;
use App\Plugins\PluginAuthFailed;
use App\Plugins\PluginCallFailed;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One Google product (Drive, Docs or Sheets) through Google's own remote MCP server for it. Each product is its own plugin with its own
 * connection and permissions, but they all sign in through the one Google Cloud OAuth client the Super Admin entered once (kept on the
 * shared "google" settings row) and come back to one shared callback address.
 *
 * Google's access tokens last about an hour, so a connection keeps a refresh token and is renewed when needed. The tool lists are
 * files in `resources/plugins`, taken from Google's servers; each tool is a read or a change by Google's own read-only hint, and anything
 * without the hint counts as a change (it waits for approval).
 */
abstract class GoogleProduct extends AbstractProvider implements AsksPermission, ListsTools
{
    /** The product's MCP server: drive, docs or sheets. */
    abstract protected function server(): string;

    /** The permissions this product needs beyond the person's email address. */
    abstract protected function scopes(): string;

    public function callbackSlug(): string
    {
        return 'google';
    }

    public function oauthOwner(): string
    {
        return 'google';
    }

    public function grantGroup(): ?string
    {
        return 'google';
    }

    public function authorizeUrl(PluginApp $app, string $state, string $verifier): string
    {
        return config('plugins.google.authorize_url').'?'.http_build_query([
            'client_id' => $this->credentials($app)->oauth_client_id, 'redirect_uri' => $this->callbackUrl(), 'response_type' => 'code',
            'scope' => 'openid email '.$this->scopes(), 'access_type' => 'offline', 'prompt' => 'consent',
            'state' => $state, 'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(PluginApp $app, string $code, string $verifier): array
    {
        $credentials = $this->credentials($app);
        $response = Http::asForm()->acceptJson()->timeout(20)->post((string) config('plugins.google.token_url'), [
            'client_id' => $credentials->oauth_client_id, 'client_secret' => $credentials->oauth_client_secret, 'code' => $code, 'grant_type' => 'authorization_code',
            'redirect_uri' => $this->callbackUrl(), 'code_verifier' => $verifier,
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new PluginCallFailed('Google did not accept the sign-in.');
        }
        $got = ['token' => $token, 'scopes' => (string) $response->json('scope', '')];
        if (is_string($refresh = $response->json('refresh_token')) && $refresh !== '') {
            $got['refresh_token'] = $refresh;
        }
        if (is_numeric($expires = $response->json('expires_in'))) {
            $got['expires_in'] = (int) $expires;
        }

        return $got;
    }

    public function identity(string $token): string
    {
        $response = Http::withToken($token)->acceptJson()->timeout(20)->get((string) config('plugins.google.userinfo_url'));
        $email = $response->json('email');
        if (! $response->successful() || ! is_string($email) || $email === '') {
            throw new PluginCallFailed('Google did not say who signed in.');
        }

        return mb_substr($email, 0, 100);
    }

    public function revoke(PluginApp $app, string $token): void
    {
        try {
            Http::asForm()->timeout(10)->post((string) config('plugins.google.revoke_url'), ['token' => $token]);
        } catch (\Throwable) {
            // nothing to do: the person can also remove access in their Google account
        }
    }

    /**
     * Reads tell Google that Thryve can put a question to the person, so Google asks (for example before showing mail other people sent)
     * instead of quietly leaving those items out. Changes keep the plain call: the person already approved them on Thryve's own card.
     */
    public function callTool(PluginConnection $connection, string $tool, array $arguments, bool $readOnly): array
    {
        $meta = $this->tools()[$tool] ?? throw new PluginCallFailed('Google does not offer that tool.');
        $url = $this->mcpUrl();

        return $this->withFreshToken($connection, fn (string $token) => $readOnly
            ? (new McpClient($token, true, $url, 'Google'))->callToolAskingFirst($meta['remote'], $arguments)
            : (new McpClient($token, false, $url, 'Google'))->callTool($meta['remote'], $arguments));
    }

    public function answerPermission(PluginConnection $connection, string $tool, array $arguments, array $permission, string $choice): array
    {
        $meta = $this->tools()[$tool] ?? throw new PluginCallFailed('Google does not offer that tool.');
        $url = $this->mcpUrl();

        return $this->withFreshToken($connection, fn (string $token) => (new McpClient($token, true, $url, 'Google'))->callToolAskingFirst($meta['remote'], $arguments,
            ['request_state' => (string) $permission['request_state'], 'key' => (string) $permission['key'], 'choice' => $choice]));
    }

    private function mcpUrl(): string
    {
        return str_replace('{server}', $this->server(), (string) config('plugins.google.mcp_url'));
    }

    /**
     * Runs a request with a working access token: renewed first when it is about to run out, and once more if Google still refuses it.
     *
     * @template T
     *
     * @param  Closure(string): T  $run
     * @return T
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed
     */
    public function fetchTools(PluginConnection $connection): array
    {
        $url = str_replace('{server}', $this->server(), (string) config('plugins.google.mcp_url'));
        $remote = $this->withFreshToken($connection, fn (string $token) => (new McpClient($token, true, $url, 'Google'))->listTools());
        $tools = [];
        foreach ($remote as $tool) {
            // The name the AI sees: letters, digits and underscores only (Google's own names can hold dots), led by google_ and the product.
            $clean = (string) preg_replace('/[^A-Za-z0-9_]/', '_', (string) $tool['name']);
            $name = 'google_'.(str_starts_with($clean, $this->server().'_') ? $clean : $this->server().'_'.$clean);
            $tools[] = [
                'name' => $name, 'remote' => (string) $tool['name'], 'server' => $this->server(), 'description' => mb_substr((string) ($tool['description'] ?? ''), 0, 600),
                'kind' => ($tool['annotations']['readOnlyHint'] ?? false) === true ? 'read' : 'write',
                'inputSchema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : ['type' => 'object', 'properties' => (object) []],
            ];
        }

        return $tools;
    }

    protected function withFreshToken(PluginConnection $connection, Closure $run): mixed
    {
        if ($connection->refresh_token && $connection->token_expires_at && $connection->token_expires_at->copy()->subSeconds(60)->isPast()) {
            $this->renew($connection);
        }
        try {
            return $run($connection->access_token);
        } catch (PluginAuthFailed $e) {
            if (! $connection->refresh_token) {
                throw $e;
            }
            $this->renew($connection);

            return $run($connection->access_token);
        }
    }

    /**
     * @throws PluginAuthFailed Google no longer accepts the refresh token: the person has to connect again
     * @throws PluginCallFailed
     */
    private function renew(PluginConnection $connection): void
    {
        $credentials = $this->credentials(PluginApp::for($this->slug()));
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post((string) config('plugins.google.token_url'), [
                'client_id' => $credentials->oauth_client_id, 'client_secret' => $credentials->oauth_client_secret, 'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException) {
            throw new PluginCallFailed('Google could not be reached.');
        }
        if (in_array($response->status(), [400, 401], true)) {
            throw new PluginAuthFailed('Google no longer accepts this login.');
        }
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new PluginCallFailed("Google answered with status {$response->status()}.");
        }
        $connection->update([
            'access_token' => $token,
            'token_expires_at' => is_numeric($expires = $response->json('expires_in')) ? now()->addSeconds((int) $expires) : null,
            'refresh_token' => is_string($new = $response->json('refresh_token')) && $new !== '' ? $new : $connection->refresh_token,
        ]);
    }
}
