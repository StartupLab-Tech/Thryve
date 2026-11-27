<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google was first one plugin; it is now three (Drive, Docs, Sheets) that share one OAuth client. The old "google" settings row stays,
 * holding only the shared client (Client ID and secret). Its on/off switch and chatbots carry over to each product, and so do the
 * people's existing connections: their token already covers all three, so each person keeps working without signing in again.
 * The tool lists are files now, so the stored list goes away. A product row the site already made empty (a page opened first) is filled in.
 */
return new class extends Migration
{
    private const PRODUCTS = ['google-drive' => 'Google Drive', 'google-docs' => 'Google Docs', 'google-sheets' => 'Google Sheets'];

    public function up(): void
    {
        $google = DB::table('plugin_apps')->where('slug', 'google')->first();
        if ($google) {
            foreach (self::PRODUCTS as $slug => $name) {
                $existing = DB::table('plugin_apps')->where('slug', $slug)->first();
                if (! $existing) {
                    DB::table('plugin_apps')->insert(['slug' => $slug, 'name' => $name, 'is_enabled' => $google->is_enabled, 'agent_ids' => $google->agent_ids, 'created_at' => now(), 'updated_at' => now()]);
                } elseif (! $existing->is_enabled && empty(json_decode((string) $existing->agent_ids, true))) {
                    DB::table('plugin_apps')->where('slug', $slug)->update(['is_enabled' => $google->is_enabled, 'agent_ids' => $google->agent_ids, 'updated_at' => now()]);
                }
            }
            DB::table('plugin_apps')->where('slug', 'google')->update(['is_enabled' => false, 'agent_ids' => null, 'name' => 'Google sign-in']);

            foreach (DB::table('plugin_connections')->where('app_slug', 'google')->get() as $connection) {
                foreach (array_keys(self::PRODUCTS) as $slug) {
                    if (! DB::table('plugin_connections')->where('user_id', $connection->user_id)->where('app_slug', $slug)->exists()) {
                        $copy = (array) $connection;
                        unset($copy['id']);
                        DB::table('plugin_connections')->insert(['app_slug' => $slug] + $copy);
                    }
                }
                DB::table('plugin_connections')->where('id', $connection->id)->delete();
            }
        }
        if (Schema::hasColumn('plugin_apps', 'tools_snapshot')) {
            Schema::table('plugin_apps', function (Blueprint $table) {
                $table->dropColumn('tools_snapshot');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plugin_apps', 'tools_snapshot')) {
            Schema::table('plugin_apps', function (Blueprint $table) {
                $table->json('tools_snapshot')->nullable();
            });
        }
    }
};
