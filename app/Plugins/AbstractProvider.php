<?php

namespace App\Plugins;

use App\Models\PluginApp;

/** What every provider shares: the tool snapshot, the blocked and default-off lists from config, the callback address and PKCE. */
abstract class AbstractProvider implements Provider
{
    /** @var array<string, array<string, array{name: string, description: string, kind: string, inputSchema: array<string, mixed>}>> */
    private static array $snapshots = [];

    public function tools(): array
    {
        $slug = $this->slug();
        if (! isset(self::$snapshots[$slug])) {
            $data = json_decode((string) file_get_contents(resource_path("plugins/{$slug}-tools.json")), true);
            self::$snapshots[$slug] = [];
            foreach ((array) ($data['tools'] ?? []) as $tool) {
                self::$snapshots[$slug][$tool['name']] = $tool;
            }
        }

        return self::$snapshots[$slug];
    }

    public function blockedTools(): array
    {
        return $this->configuredBlocked();
    }

    /** @return list<string> the tools `config/plugins.php` names as never offered */
    protected function configuredBlocked(): array
    {
        return array_values((array) config("plugins.{$this->slug()}.blocked_tools"));
    }

    public function blocks(string $tool): bool
    {
        return in_array($tool, $this->configuredBlocked(), true);
    }

    public function defaultOffTools(): array
    {
        return array_values((array) config("plugins.{$this->slug()}.default_off_tools"));
    }

    public function adminNote(): ?string
    {
        return null;
    }

    public function configured(PluginApp $app): bool
    {
        $credentials = $this->credentials($app);

        return filled($credentials->oauth_client_id) && filled($credentials->oauth_client_secret);
    }

    public function callbackUrl(): string
    {
        return url("/api/plugins/{$this->callbackSlug()}/callback");
    }

    public function callbackSlug(): string
    {
        return $this->slug();
    }

    public function oauthOwner(): string
    {
        return $this->slug();
    }

    public function grantGroup(): ?string
    {
        return null;
    }

    /** The settings row holding the OAuth client this plugin signs in with. */
    protected function credentials(PluginApp $app): PluginApp
    {
        $owner = $this->oauthOwner();

        return $owner === $this->slug() ? $app : PluginApp::for($owner, (string) config("plugins.shared_oauth.{$owner}.name", $owner));
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
