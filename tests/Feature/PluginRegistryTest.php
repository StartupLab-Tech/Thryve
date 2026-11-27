<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\PluginAction;
use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginAuthFailed;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginTicket;
use App\Plugins\Provider;
use App\Plugins\Providers\GitHubProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** A second plugin plugs into the shared system without touching GitHub: tools, gateway, approval and sign-in all follow the contract. */
class PluginRegistryTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const KEY = 'thryve_plug_registry_key';

    private Agent $agent;

    private User $user;

    private Conversation $conversation;

    private string $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        config(['plugins.providers' => [GitHubProvider::class, FakeNotesProvider::class]]);
        PluginRegistry::forget();
        $this->agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        $this->user = $this->makeUser(Role::EMPLOYEE);
        PluginApp::gateway()->update(['gateway_key_hash' => hash('sha256', self::KEY)]);
        PluginApp::for('github')->update(['is_enabled' => true, 'agent_ids' => [$this->agent->id]]);
        PluginApp::for('notes')->update(['is_enabled' => true, 'agent_ids' => [$this->agent->id], 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'secret']);
        PluginConnection::factory()->create(['user_id' => $this->user->id, 'app_slug' => 'github']);
        PluginConnection::factory()->create(['user_id' => $this->user->id, 'app_slug' => 'notes', 'access_token' => 'notes-token']);
        $this->conversation = Conversation::create(['user_id' => $this->user->id, 'agent_id' => $this->agent->id, 'title' => 'Notes', 'plugins' => ['notes']]);
        $this->ticket = PluginTicket::issue($this->user, $this->conversation, 'chat-r1');
        FakeNotesProvider::$calls = [];
    }

    protected function tearDown(): void
    {
        PluginRegistry::forget();
        parent::tearDown();
    }

    private function gatewayRpc(string $method, array $params = [])
    {
        return $this->postJson('/api/plugins/gateway', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['Authorization' => 'Bearer '.self::KEY]);
    }

    private function callTool(string $tool, array $arguments = [])
    {
        return $this->gatewayRpc('tools/call', ['name' => $tool, 'arguments' => ['ticket' => $this->ticket] + $arguments]);
    }

    public function test_the_gateway_offers_the_tools_of_every_enabled_plugin(): void
    {
        $names = collect($this->gatewayRpc('tools/list')->assertOk()->json('result.tools'))->pluck('name');

        $this->assertTrue($names->contains('issue_read'));
        $this->assertTrue($names->contains('notes_search'));
        $this->assertTrue($names->contains('notes_create'));

        PluginApp::for('notes')->update(['is_enabled' => false]);
        $names = collect($this->gatewayRpc('tools/list')->json('result.tools'))->pluck('name');
        $this->assertFalse($names->contains('notes_search'));
        $this->assertTrue($names->contains('issue_read'), 'one plugin being off does not hide the others');
    }

    public function test_a_read_goes_to_its_own_plugin_with_that_persons_connection(): void
    {
        $this->callTool('notes_search', ['q' => 'budget'])->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.content.0.text', 'found: budget');

        $this->assertSame([['notes_search', ['q' => 'budget'], true, 'notes-token']], FakeNotesProvider::$calls);
        $this->assertDatabaseHas('plugin_actions', ['app_slug' => 'notes', 'tool' => 'notes_search', 'kind' => 'read', 'status' => 'done']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'plugin.notes.read']);
    }

    public function test_a_plugin_is_refused_unless_this_chat_switched_it_on(): void
    {
        $this->conversation->update(['plugins' => ['github']]);

        $this->callTool('notes_search', ['q' => 'x'])->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Notes is not available for this request.');

        $this->assertSame([], FakeNotesProvider::$calls);
    }

    public function test_a_change_waits_for_approval_and_then_runs_in_its_own_plugin(): void
    {
        $this->callTool('notes_create', ['title' => 'Plan'])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertSame([], FakeNotesProvider::$calls, 'nothing runs before approval');
        $action = PluginAction::where('app_slug', 'notes')->sole();
        $this->assertSame('pending', $action->status);

        [, $cookie] = $this->signIn($this->user);
        $this->device($cookie)->postJson("/api/plugin-actions/{$action->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'done')
            ->assertJsonPath('data.message.content', fn ($text) => str_starts_with($text, 'Notes change made (you approved it)'));

        $this->assertSame([['notes_create', ['title' => 'Plan'], false, 'notes-token']], FakeNotesProvider::$calls);
    }

    public function test_the_plugins_list_in_chat_and_the_chat_validation_know_every_plugin(): void
    {
        [, $cookie] = $this->signIn($this->user);

        $this->device($cookie)->getJson('/api/chat/plugins?agent_id='.$this->agent->id)->assertOk()
            ->assertJsonPath('data.plugins', [['slug' => 'github', 'name' => 'GitHub'], ['slug' => 'notes', 'name' => 'Notes']]);

        PluginConnection::where('app_slug', 'notes')->delete();
        $this->device($cookie)->getJson('/api/chat/plugins?agent_id='.$this->agent->id)->assertJsonPath('data.plugins', [['slug' => 'github', 'name' => 'GitHub']]);
    }

    public function test_connecting_uses_the_plugins_own_sign_in_and_an_unknown_plugin_is_not_found(): void
    {
        [, $cookie] = $this->signIn($this->user);

        $response = $this->device($cookie)->get('/api/plugins/notes/connect');
        $response->assertRedirect();
        $this->assertStringStartsWith('https://notes.example.com/authorize?', $response->headers->get('Location'));
        $this->device($cookie)->get('/api/plugins/nothing/connect')->assertNotFound();
        $this->device($cookie)->deleteJson('/api/plugins/nothing/connection')->assertNotFound();
    }

    public function test_a_login_the_app_refuses_marks_that_connection_for_reconnecting(): void
    {
        FakeNotesProvider::$refuse = true;

        $this->callTool('notes_search', ['q' => 'x'])->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame('needs_reconnect', PluginConnection::where('app_slug', 'notes')->sole()->status);
        $this->assertSame('active', PluginConnection::where('app_slug', 'github')->sole()->status);
    }

    public function test_an_installation_with_the_key_on_the_github_row_keeps_working_after_the_update(): void
    {
        PluginApp::gateway()->update(['gateway_key_hash' => null, 'gateway_key_fingerprint' => null]); // a request had already made the empty company row
        PluginApp::github()->update(['gateway_key_hash' => hash('sha256', 'thryve_plug_old_key'), 'gateway_key_fingerprint' => 'abcd1234']);

        (require database_path('migrations/2026_11_27_024340_move_plugin_gateway_key_to_company_row.php'))->up();

        $this->assertSame(hash('sha256', 'thryve_plug_old_key'), PluginApp::gateway()->gateway_key_hash);
        $this->assertSame('abcd1234', PluginApp::gateway()->gateway_key_fingerprint);
        $this->assertNull(PluginApp::github()->gateway_key_hash);
        $this->postJson('/api/plugins/gateway', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer thryve_plug_old_key'])->assertOk();
    }

    public function test_two_plugins_may_not_offer_the_same_tool_name(): void
    {
        config(['plugins.providers' => [GitHubProvider::class, FakeClashingProvider::class]]);
        PluginRegistry::forget();

        $this->expectException(LogicException::class);
        PluginRegistry::all();
    }
}

/** A stand-in second plugin. */
class FakeNotesProvider implements Provider
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: bool, 3: string}> */
    public static array $calls = [];

    public static bool $refuse = false;

    public function slug(): string
    {
        return 'notes';
    }

    public function name(): string
    {
        return 'Notes';
    }

    public function description(): string
    {
        return 'Search and write notes.';
    }

    public function tools(): array
    {
        $schema = ['type' => 'object', 'properties' => []];

        return [
            'notes_search' => ['name' => 'notes_search', 'description' => 'Search notes', 'kind' => 'read', 'inputSchema' => $schema],
            'notes_create' => ['name' => 'notes_create', 'description' => 'Create a note', 'kind' => 'write', 'inputSchema' => $schema],
        ];
    }

    public function blockedTools(): array
    {
        return [];
    }

    public function blocks(string $tool): bool
    {
        return false;
    }

    public function defaultOffTools(): array
    {
        return [];
    }

    public function adminNote(): ?string
    {
        return null;
    }

    public function configured(PluginApp $app): bool
    {
        return filled($app->oauth_client_id) && filled($app->oauth_client_secret);
    }

    public function callbackUrl(): string
    {
        return url('/api/plugins/notes/callback');
    }

    public function callbackSlug(): string
    {
        return 'notes';
    }

    public function oauthOwner(): string
    {
        return 'notes';
    }

    public function grantGroup(): ?string
    {
        return null;
    }

    public function authorizeUrl(PluginApp $app, string $state, string $verifier): string
    {
        return 'https://notes.example.com/authorize?'.http_build_query(['client_id' => $app->oauth_client_id, 'state' => $state]);
    }

    public function exchange(PluginApp $app, string $code, string $verifier): array
    {
        return ['token' => 'notes-token', 'scopes' => ''];
    }

    public function identity(string $token): string
    {
        return 'notes-user';
    }

    public function revoke(PluginApp $app, string $token): void {}

    public function callTool(PluginConnection $connection, string $tool, array $arguments, bool $readOnly): array
    {
        if (self::$refuse) {
            self::$refuse = false;
            throw new PluginAuthFailed('refused');
        }
        self::$calls[] = [$tool, $arguments, $readOnly, $connection->access_token];

        return ['text' => $tool === 'notes_search' ? 'found: '.($arguments['q'] ?? '') : 'created', 'is_error' => false];
    }
}

/** A second plugin that wrongly reuses one of GitHub's tool names. */
class FakeClashingProvider extends FakeNotesProvider
{
    public function slug(): string
    {
        return 'clash';
    }

    public function tools(): array
    {
        return ['issue_read' => ['name' => 'issue_read', 'description' => 'clash', 'kind' => 'read', 'inputSchema' => ['type' => 'object']]];
    }
}
