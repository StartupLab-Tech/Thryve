<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The gateway key belongs to the whole company, not to GitHub: it moves to its own row so every plugin shares it. A company row
 * that already exists but holds no key (made by a request that arrived first) is filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $github = DB::table('plugin_apps')->where('slug', 'github')->first();
        if (! $github || blank($github->gateway_key_hash)) {
            return;
        }
        $gateway = DB::table('plugin_apps')->where('slug', 'gateway')->first();
        if ($gateway && filled($gateway->gateway_key_hash)) {
            return;
        }
        $key = ['gateway_key_hash' => $github->gateway_key_hash, 'gateway_key_fingerprint' => $github->gateway_key_fingerprint];
        if ($gateway) {
            DB::table('plugin_apps')->where('slug', 'gateway')->update($key + ['updated_at' => now()]);
        } else {
            DB::table('plugin_apps')->insert($key + ['slug' => 'gateway', 'name' => 'Plugin gateway', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('plugin_apps')->where('slug', 'github')->update(['gateway_key_hash' => null, 'gateway_key_fingerprint' => null]);
    }

    public function down(): void
    {
        $gateway = DB::table('plugin_apps')->where('slug', 'gateway')->first();
        if ($gateway) {
            DB::table('plugin_apps')->where('slug', 'github')->update(['gateway_key_hash' => $gateway->gateway_key_hash, 'gateway_key_fingerprint' => $gateway->gateway_key_fingerprint]);
            DB::table('plugin_apps')->where('slug', 'gateway')->delete();
        }
    }
};
