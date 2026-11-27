<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some apps (Google) hand out tokens that expire within the hour, so a connection also keeps an encrypted refresh token and the
 * time the access token runs out. An app whose tool list is read from the app itself (Google) keeps that list on its settings row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_connections', function (Blueprint $table) {
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
        });
        Schema::table('plugin_apps', function (Blueprint $table) {
            $table->json('tools_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plugin_connections', function (Blueprint $table) {
            $table->dropColumn(['refresh_token', 'token_expires_at']);
        });
        Schema::table('plugin_apps', function (Blueprint $table) {
            $table->dropColumn('tools_snapshot');
        });
    }
};
