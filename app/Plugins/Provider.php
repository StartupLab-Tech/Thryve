<?php

namespace App\Plugins;

use App\Models\PluginApp;
use App\Models\PluginConnection;

/**
 * One outside app the AI can work in (GitHub, Google Drive...). Everything else in the plugin system (the Plugins page, the @ list,
 * the Approve cards, the encrypted connections, the audit log and the gateway) is shared and only talks to this contract.
 *
 * A new plugin is a class that implements this, a tool list in `resources/plugins/{slug}-tools.json`, an entry in `config/plugins.php`
 * and a logo. Tool names must be unique across all plugins.
 */
interface Provider
{
    public function slug(): string;

    public function name(): string;

    public function description(): string;

    /** @return array<string, array{name: string, description: string, kind: string, inputSchema: array<string, mixed>}> every tool Thryve may offer, by name; kind is read or write */
    public function tools(): array;

    /** @return list<string> never offered, whatever an admin switches on */
    public function blockedTools(): array;

    /** Is this tool never offered, whatever an admin switches on? */
    public function blocks(string $tool): bool;

    /** @return list<string> offered only after an admin switches them on */
    public function defaultOffTools(): array;

    /** A short hint shown to the Super Admin on this plugin's setup card, if its setup needs explaining. */
    public function adminNote(): ?string;

    /** Has the Super Admin entered everything needed to sign people in? */
    public function configured(PluginApp $app): bool;

    public function callbackUrl(): string;

    /** The last part of the callback address. Plugins that sign in through one shared client (Drive, Docs, Sheets) share one callback. */
    public function callbackSlug(): string;

    /** The settings row that holds the OAuth client (Client ID and secret): the plugin's own, or a shared one such as "google". */
    public function oauthOwner(): string;

    /** Plugins in the same group share one login grant at the app, so disconnecting one must not revoke it while another is still connected. */
    public function grantGroup(): ?string;

    public function authorizeUrl(PluginApp $app, string $state, string $verifier): string;

    /**
     * @return array{token: string, scopes: string, refresh_token?: string, expires_in?: int} the last two only for apps whose tokens expire
     *
     * @throws PluginCallFailed
     */
    public function exchange(PluginApp $app, string $code, string $verifier): array;

    /**
     * Who signed in, in words the person recognises (a username or an email address).
     *
     * @throws PluginCallFailed
     */
    public function identity(string $token): string;

    /** Best effort: if the app cannot be reached the stored token is still deleted. */
    public function revoke(PluginApp $app, string $token): void;

    /**
     * Runs one tool as the connected person. Reads may run freely; a change is only ever run after the person approved it.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{text: string, is_error: bool}
     *
     * @throws PluginAuthFailed the app refused the person's login
     * @throws PluginCallFailed
     */
    public function callTool(PluginConnection $connection, string $tool, array $arguments, bool $readOnly): array;
}
