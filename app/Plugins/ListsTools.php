<?php

namespace App\Plugins;

use App\Models\PluginConnection;

/**
 * A plugin whose tool list is taken from the app's own server rather than copied from documentation (Google). The list is saved as a
 * file in `resources/plugins` by `php artisan thryve:plugin-tools`, using one connected person's login. Nothing reads it at run time.
 */
interface ListsTools
{
    /**
     * Every tool the app's servers offer, in the shape the tool file uses.
     *
     * @return list<array{name: string, remote: string, server: string, description: string, kind: string, inputSchema: array<string, mixed>}>
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed
     */
    public function fetchTools(PluginConnection $connection): array;
}
