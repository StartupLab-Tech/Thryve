<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\PluginApp;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** Setting GitHub up for the company: admins look, only a Super Admin changes, and secrets never come back. */
class PluginAdminTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private Agent $chat;

    private Agent $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        $this->chat = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true, 'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        $this->report = Agent::create(['name' => 'Report AI', 'slug' => 'report-ai', 'base_url' => 'https://general.example.com', 'profile' => 'report-ai', 'type' => 'report', 'outbound_key' => 'key-for-report-1234567890', 'outbound_fingerprint' => 'efgh5678']);
    }

    private function read(string $cookie)
    {
        return $this->device($cookie)->getJson('/api/plugin-apps');
    }

    public function test_admins_can_look_but_only_a_super_admin_can_change_anything(): void
    {
        $admin = $this->cookieFor(Role::ADMIN);
        $employee = $this->cookieFor(Role::EMPLOYEE);

        $this->read($admin)->assertOk();
        $this->read($this->cookieFor(Role::SUPER_ADMIN))->assertOk();
        $this->read($employee)->assertStatus(403);
        $this->device(null)->getJson('/api/plugin-apps')->assertStatus(401);

        foreach ([['patchJson', '/api/plugin-apps/github', ['is_enabled' => true]], ['putJson', '/api/plugin-apps/github/oauth', ['client_id' => 'Ov23x']], ['postJson', '/api/plugin-apps/gateway-key', []]] as [$method, $url, $body]) {
            $this->device($admin)->{$method}($url, $body)->assertStatus(403);
            $this->device($employee)->{$method}($url, $body)->assertStatus(403);
        }
        $this->assertFalse(PluginApp::github()->is_enabled);
        $this->assertSame(0, AuditEntry::where('action', 'like', 'plugin_app.%')->count());
    }

    public function test_the_page_shows_the_snapshot_with_copilot_tools_off_and_only_chat_agents_to_choose_from(): void
    {
        $r = $this->read($this->cookieFor(Role::SUPER_ADMIN))->assertOk();

        $app = $r->json('data.apps.0');
        $tools = collect($app['tools'])->keyBy('name');
        $this->assertCount(49, $tools);
        $this->assertSame('read', $tools['issue_read']['kind']);
        $this->assertSame('write', $tools['issue_write']['kind']);
        $this->assertTrue($tools['issue_read']['enabled']);
        $this->assertFalse($tools['assign_copilot_to_issue']['enabled']);
        $this->assertTrue($tools['assign_copilot_to_issue']['default_off']);
        $this->assertSame(['General'], array_column($r->json('data.agents'), 'name'));
        $this->assertStringEndsWith('/api/plugins/github/callback', $app['callback_url']);
        $this->assertStringEndsWith('/api/plugins/gateway', $r->json('data.gateway.url'));
        $this->assertFalse($app['is_enabled']);
        $this->assertFalse($app['oauth_secret_set']);
        $this->assertFalse($r->json('data.gateway.key_set'));
    }

    public function test_enabling_the_app_choosing_chatbots_and_switching_tools(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($super)->patchJson('/api/plugin-apps/github', ['is_enabled' => true, 'agent_ids' => [$this->chat->id], 'tools' => ['assign_copilot_to_issue' => true, 'issue_read' => false]])->assertOk();

        $tools = collect($r->json('data.apps.0.tools'))->keyBy('name');
        $this->assertTrue($tools['assign_copilot_to_issue']['enabled']);
        $this->assertFalse($tools['issue_read']['enabled']);
        $this->assertTrue($tools['list_issues']['enabled'], 'tools not mentioned keep their setting');
        $app = PluginApp::github();
        $this->assertTrue($app->is_enabled);
        $this->assertSame([$this->chat->id], $app->agent_ids);
        $entry = AuditEntry::where('action', 'plugin_app.updated')->sole();
        $this->assertEqualsCanonicalizing(['assign_copilot_to_issue', 'issue_read'], $entry->metadata['tools_changed']);

        $this->device($super)->patchJson('/api/plugin-apps/github', ['tools' => ['issue_read' => true]])->assertOk();
        $this->assertTrue(PluginApp::github()->tools['assign_copilot_to_issue'], 'an earlier switch is kept');
    }

    public function test_only_chat_chatbots_and_real_tools_can_be_chosen_and_a_blocked_tool_never(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($super)->patchJson('/api/plugin-apps/github', ['agent_ids' => [$this->report->id]])->assertStatus(422);
        $this->device($super)->patchJson('/api/plugin-apps/github', ['agent_ids' => [9999]])->assertStatus(422);
        $this->device($super)->patchJson('/api/plugin-apps/github', ['tools' => ['no_such_tool' => true]])->assertStatus(422)->assertJsonPath('error.details.tools.0', 'That tool cannot be switched on or off.');
        $this->device($super)->patchJson('/api/plugin-apps/github', ['tools' => ['delete_repository' => true]])->assertStatus(422);
        $this->device($super)->patchJson('/api/plugin-apps/github', ['tools' => ['issue_read' => 'maybe']])->assertStatus(422);

        $app = PluginApp::github();
        $this->assertSame([], (array) $app->agent_ids);
        $this->assertNull($app->tools);
    }

    public function test_the_oauth_secret_is_write_only_and_an_empty_one_keeps_the_saved_secret(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($super)->putJson('/api/plugin-apps/github/oauth', ['client_id' => 'Ov23liTestClient', 'client_secret' => 'super-secret-value'])->assertOk();

        $r->assertJsonPath('data.apps.0.oauth_client_id', 'Ov23liTestClient')->assertJsonPath('data.apps.0.oauth_secret_set', true);
        $this->assertStringNotContainsString('super-secret-value', $r->getContent());
        $this->assertStringNotContainsString('super-secret-value', (string) DB::table('plugin_apps')->value('oauth_client_secret'), 'stored encrypted');
        $this->assertStringNotContainsString('super-secret-value', $this->read($super)->getContent());

        $this->device($super)->putJson('/api/plugin-apps/github/oauth', ['client_id' => 'Ov23liOther', 'client_secret' => ''])->assertOk();
        $app = PluginApp::github();
        $this->assertSame(['Ov23liOther', 'super-secret-value'], [$app->oauth_client_id, $app->oauth_client_secret]);
        $this->assertStringNotContainsString('super-secret-value', json_encode(DB::table('audit_entries')->get()));
        $this->device($super)->putJson('/api/plugin-apps/github/oauth', ['client_id' => 'bad id!'])->assertStatus(422);
    }

    public function test_the_gateway_key_is_shown_once_only_its_hash_is_kept_and_a_new_one_replaces_the_old(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $first = $this->device($super)->postJson('/api/plugin-apps/gateway-key')->assertOk();

        $key = $first->json('data.key');
        $this->assertStringStartsWith('thryve_plug_', $key);
        $this->assertSame(8, strlen($first->json('data.fingerprint')));
        $app = PluginApp::gateway();
        $this->assertSame(hash('sha256', $key), $app->gateway_key_hash);
        $this->assertStringNotContainsString($key, $this->read($super)->getContent());
        $this->read($super)->assertJsonPath('data.gateway.key_set', true)->assertJsonPath('data.gateway.fingerprint', $app->gateway_key_fingerprint);
        $this->assertStringNotContainsString($key, json_encode(DB::table('audit_entries')->get()));
        $this->device(null)->postJson('/api/plugins/gateway', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => "Bearer {$key}"])->assertOk();

        $second = $this->device($super)->postJson('/api/plugin-apps/gateway-key')->assertOk();

        $this->assertNotSame($key, $second->json('data.key'));
        $this->device(null)->postJson('/api/plugins/gateway', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => "Bearer {$key}"])->assertStatus(401);
        $this->device(null)->postJson('/api/plugins/gateway', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer '.$second->json('data.key')])->assertOk();
        $this->assertSame(2, AuditEntry::where('action', 'plugin_app.gateway_key_issued')->count());
    }
}
