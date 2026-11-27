<?php

namespace App\Plugins;

use App\Models\PluginConnection;

/**
 * A plugin that must show the person the real thing before it is approved, not the AI's description of it (Gmail's send). When the AI
 * proposes a change, the plugin looks the thing up itself and returns the arguments to store on the Approve card; anything the AI
 * made up is replaced. It can refuse the proposal by throwing.
 */
interface PreviewsChanges
{
    /**
     * @param  array<string, mixed>  $arguments  what the AI asked for
     * @return array<string, mixed> what is shown on the Approve card and later run; keys starting with "_" are kept but not shown
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed the message is told to the AI, so it must be short and plain
     */
    public function previewChange(PluginConnection $connection, string $tool, array $arguments): array;
}
