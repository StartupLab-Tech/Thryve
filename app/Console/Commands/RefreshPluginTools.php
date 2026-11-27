<?php

namespace App\Console\Commands;

use App\Models\PluginConnection;
use App\Plugins\ListsTools;
use App\Plugins\PluginAuthFailed;
use App\Plugins\PluginCallFailed;
use App\Plugins\PluginRegistry;
use Illuminate\Console\Command;

/** Reads a plugin's tool list from the app's own servers, with one connected person's login, and saves it as the plugin's tool file. */
class RefreshPluginTools extends Command
{
    protected $signature = 'thryve:plugin-tools {slug : the plugin, for example google-gmail} {--user= : id of the person whose connection to use (default: any connected person)} {--path= : write the file here instead of resources/plugins}';

    protected $description = 'Save the tool list of a plugin (Google) from the app\'s own servers; run it after someone has connected the plugin';

    public function handle(): int
    {
        $provider = PluginRegistry::find((string) $this->argument('slug'));
        if (! $provider instanceof ListsTools) {
            $this->error('That plugin does not read its tools from the app. Its list is a file kept with the project.');

            return self::FAILURE;
        }
        $query = PluginConnection::active()->where('app_slug', $provider->slug());
        if ($this->option('user')) {
            $query->where('user_id', (int) $this->option('user'));
        }
        $connection = $query->orderBy('id')->first();
        if (! $connection) {
            $this->error("Nobody is connected to {$provider->name()} yet. Connect it on the Plugins page first.");

            return self::FAILURE;
        }
        try {
            $tools = $provider->fetchTools($connection);
        } catch (PluginAuthFailed) {
            $connection->update(['status' => 'needs_reconnect']);
            $this->error("{$provider->name()} refused that login. Connect again on the Plugins page.");

            return self::FAILURE;
        } catch (PluginCallFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($tools === []) {
            $this->error("{$provider->name()} returned no tools, so the saved list was left as it is.");

            return self::FAILURE;
        }
        usort($tools, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        $file = $this->option('path') ?: resource_path("plugins/{$provider->slug()}-tools.json");
        file_put_contents($file, json_encode(['source' => "{$provider->name()}'s own MCP server (tools/list), read or change by its read-only hint", 'tools' => $tools], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $reads = count(array_filter($tools, fn (array $t) => $t['kind'] === 'read'));
        $this->info(count($tools)." tools saved to {$file} ({$reads} read, ".(count($tools) - $reads).' change).');
        foreach ($tools as $t) {
            $this->line(sprintf('  %-7s %s', $t['kind'], $t['name']));
        }
        $blocked = array_values(array_filter(array_column($tools, 'name'), fn (string $name) => $provider->blocks($name)));
        if ($blocked !== []) {
            $this->warn('Never offered (blocked): '.implode(', ', $blocked));
        }

        return self::SUCCESS;
    }
}
