<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plugins (outside apps the AI can work in, starting with GitHub): the app switch, each person's own login, and every use.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_apps', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 100);
            $table->boolean('is_enabled')->default(false);
            $table->json('agent_ids')->nullable(); // the chatbots that may use this app
            $table->json('tools')->nullable(); // per-tool on/off overrides, by tool name
            $table->string('oauth_client_id', 100)->nullable();
            $table->text('oauth_client_secret')->nullable(); // encrypted
            $table->string('gateway_key_hash', 64)->nullable(); // the key Hermes uses to call Thryve's gateway; only the hash is kept
            $table->string('gateway_key_fingerprint', 8)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plugin_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app_slug', 32);
            $table->text('access_token'); // encrypted, never sent to the browser or to Hermes
            $table->string('external_login', 100)->nullable();
            $table->string('scopes', 255)->nullable();
            $table->string('status', 20)->default('active'); // active | needs_reconnect
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'app_slug']);
        });

        Schema::create('plugin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_id', 64)->nullable();
            $table->string('app_slug', 32);
            $table->string('tool', 100);
            $table->string('kind', 8); // read | write
            $table->string('status', 16); // reads: done | failed. changes: pending -> approved -> done | failed, or denied | expired
            $table->string('summary', 255);
            $table->text('arguments')->nullable(); // encrypted; only while a change waits for approval
            $table->string('arguments_hash', 64)->nullable();
            $table->string('result_summary', 255)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_actions');
        Schema::dropIfExists('plugin_connections');
        Schema::dropIfExists('plugin_apps');
    }
};
