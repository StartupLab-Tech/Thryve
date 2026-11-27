<?php

namespace App\Plugins\Providers;

use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Plugins\AbstractProvider;
use App\Plugins\McpClient;
use App\Plugins\PluginCallFailed;
use Illuminate\Support\Facades\Http;

/**
 * GitHub, signed in with the company's OAuth App (web flow with PKCE). The person's own token is used for everything: reads go to
 * GitHub's read-only MCP address, changes to its full one.
 */
final class GitHubProvider extends AbstractProvider
{
    public function slug(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function description(): string
    {
        return 'Read your repositories, issues and pull requests, and propose changes that wait for your approval.';
    }

    public function authorizeUrl(PluginApp $app, string $state, string $verifier): string
    {
        return config('plugins.github.authorize_url').'?'.http_build_query([
            'client_id' => $app->oauth_client_id, 'redirect_uri' => $this->callbackUrl(), 'scope' => config('plugins.github.scopes'),
            'state' => $state, 'code_challenge' => self::challenge($verifier), 'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(PluginApp $app, string $code, string $verifier): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post((string) config('plugins.github.token_url'), [
            'client_id' => $app->oauth_client_id, 'client_secret' => $app->oauth_client_secret, 'code' => $code,
            'redirect_uri' => $this->callbackUrl(), 'code_verifier' => $verifier,
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new PluginCallFailed('GitHub did not accept the sign-in.');
        }

        return ['token' => $token, 'scopes' => (string) $response->json('scope', '')];
    }

    public function identity(string $token): string
    {
        $response = Http::withToken($token)->acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(20)->get(config('plugins.github.api_url').'/user');
        $login = $response->json('login');
        if (! $response->successful() || ! is_string($login) || $login === '') {
            throw new PluginCallFailed('GitHub did not say who signed in.');
        }

        return mb_substr($login, 0, 100);
    }

    public function revoke(PluginApp $app, string $token): void
    {
        try {
            Http::withBasicAuth((string) $app->oauth_client_id, (string) $app->oauth_client_secret)->acceptJson()->asJson()->timeout(10)
                ->delete(config('plugins.github.api_url').'/applications/'.$app->oauth_client_id.'/grant', ['access_token' => $token]);
        } catch (\Throwable) {
            // nothing to do: the person can also revoke it on GitHub
        }
    }

    public function callTool(PluginConnection $connection, string $tool, array $arguments, bool $readOnly): array
    {
        return (new McpClient($connection->access_token, $readOnly))->callTool($tool, $arguments);
    }
}
