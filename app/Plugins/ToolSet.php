<?php

namespace App\Plugins;

use App\Models\PluginApp;

/**
 * The tools Thryve may offer for one plugin: the snapshot taken from the app's own tool list, each marked read or write.
 * A tool that is not in the snapshot is never offered.
 */
final class ToolSet
{
    public function __construct(private Provider $provider) {}

    /** @return array<string, array{name: string, description: string, kind: string, inputSchema: array<string, mixed>}> */
    public function all(): array
    {
        return $this->provider->tools();
    }

    /** @return array{name: string, description: string, kind: string, inputSchema: array<string, mixed>}|null */
    public function find(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    public function blocked(string $name): bool
    {
        return in_array($name, $this->provider->blockedTools(), true);
    }

    /** On unless blocked or not in the snapshot; an admin's switch wins, else the tools listed as off by default start off. */
    public function enabled(PluginApp $app, string $name): bool
    {
        if ($this->find($name) === null || $this->blocked($name)) {
            return false;
        }
        $override = ((array) $app->tools)[$name] ?? null;

        return $override !== null ? (bool) $override : ! in_array($name, $this->provider->defaultOffTools(), true);
    }

    /** @return list<array{name: string, description: string, kind: string, inputSchema: array<string, mixed>}> */
    public function enabledFor(PluginApp $app): array
    {
        return array_values(array_filter($this->all(), fn (array $tool) => $this->enabled($app, $tool['name'])));
    }
}
