<?php

namespace App\Plugins;

use LogicException;

/** The plugins this installation knows, from `config('plugins.providers')`. Tool names are unique across all of them. */
final class PluginRegistry
{
    /** @var array<string, Provider>|null */
    private static ?array $providers = null;

    /** @var array<string, string>|null tool name => plugin slug */
    private static ?array $owners = null;

    /** @return array<string, Provider> by slug */
    public static function all(): array
    {
        if (self::$providers === null) {
            self::$providers = [];
            self::$owners = [];
            foreach ((array) config('plugins.providers') as $class) {
                $provider = app($class);
                self::$providers[$provider->slug()] = $provider;
                foreach (array_keys($provider->tools()) as $tool) {
                    if (isset(self::$owners[$tool])) {
                        throw new LogicException("The tool {$tool} is offered by both ".self::$owners[$tool]." and {$provider->slug()}.");
                    }
                    self::$owners[$tool] = $provider->slug();
                }
            }
        }

        return self::$providers;
    }

    public static function find(string $slug): ?Provider
    {
        return self::all()[$slug] ?? null;
    }

    public static function ownerOfTool(string $tool): ?Provider
    {
        self::all();

        return isset(self::$owners[$tool]) ? self::$providers[self::$owners[$tool]] : null;
    }

    public static function forget(): void
    {
        self::$providers = self::$owners = null;
    }
}
