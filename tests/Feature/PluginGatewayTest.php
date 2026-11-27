<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\Conversation;
use App\Models\PluginAction;
use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginTicket;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The gateway Hermes calls: who may use it, what it offers, reads that go through, and changes that only wait for approval. */
class PluginGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'thryve_plug_test_key';

    private const TOKEN = 'gho_employee_token_123456';

    private Agent $agent;

    private User $user;

    private PluginApp $pluginApp;

    private PluginConnection $connection;

    private Conversation $conversation;

    private string $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        $this->agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        $this->user = User::factory()->role(Role::EMPLOYEE)->create();
        $this->pluginApp = PluginApp::factory()->enabledFor($this->agent->id)->create();
        PluginApp::gateway()->update(['gateway_key_hash' => hash('sha256', self::KEY)]);
        $this->connection = PluginConnection::factory()->create(['user_id' => $this->user->id, 'access_token' => self::TOKEN]);
        $this->conversation = Conversation::create(['user_id' => $this->user->id, 'agent_id' => $this->agent->id, 'title' => 'Hello', 'plugins' => ['github']]);
        $this->ticket = PluginTicket::issue($this->user, $this->conversation, 'chat-req-1');
    }

    private function gatewayRpc(string $method, array $params = [], ?string $key = self::KEY, ?int $id = 1)
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method] + ($id !== null ? ['id' => $id] : []) + ($params ? ['params' => $params] : []);

        return $this->postJson('/api/plugins/gateway', $body, $key ? ['Authorization' => "Bearer {$key}"] : []);
    }

    private function callTool(string $tool, array $arguments = [], ?string $ticket = null)
    {
        return $this->gatewayRpc('tools/call', ['name' => $tool, 'arguments' => ['ticket' => $ticket ?? $this->ticket] + $arguments]);
    }

    private function fakeGitHub(string $text = 'two open issues', int $status = 200): void
    {
        Http::fake(function (Request $request) use ($text, $status) {
            if ($status !== 200) {
                return Http::response('', $status);
            }

            return match ($request['method']) {
                'initialize' => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])."\n\n", 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 's1']),
                'notifications/initialized' => Http::response('', 202),
                default => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
            };
        });
    }

    private function assertRefused($response, string $text = 'GitHub is not available for this request.'): void
    {
        $response->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', $text);
    }

    // ---- who may call --------------------------------------------------------------------------------

    public function test_the_gateway_needs_its_key(): void
    {
        $this->gatewayRpc('ping', [], null)->assertStatus(401);
        $this->gatewayRpc('ping', [], 'thryve_plug_wrong')->assertStatus(401);
        PluginApp::gateway()->update(['gateway_key_hash' => null]);
        $this->gatewayRpc('ping', [], self::KEY)->assertStatus(401);
    }

    public function test_it_speaks_enough_mcp_for_hermes_to_connect(): void
    {
        $this->gatewayRpc('initialize', ['protocolVersion' => '2025-11-25'])->assertOk()->assertJsonPath('result.protocolVersion', '2025-11-25')->assertJsonPath('result.serverInfo.name', 'thryve-plugins');
        $this->gatewayRpc('initialize', ['protocolVersion' => '1999-01-01'])->assertJsonPath('result.protocolVersion', '2025-06-18');
        $this->gatewayRpc('notifications/initialized', [], self::KEY, null)->assertStatus(202);
        $this->gatewayRpc('ping')->assertOk();
        $this->gatewayRpc('no/such/method')->assertOk()->assertJsonPath('error.code', -32601);
    }

    // ---- what it offers ------------------------------------------------------------------------------

    public function test_the_tool_list_has_only_enabled_tools_each_asking_for_the_ticket(): void
    {
        $tools = collect($this->gatewayRpc('tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertTrue($tools->has('issue_read'));
        $this->assertTrue($tools['issue_read']['annotations']['readOnlyHint']);
        $this->assertFalse($tools['issue_write']['annotations']['readOnlyHint']);
        $this->assertContains('ticket', $tools['issue_read']['inputSchema']['required']);
        $this->assertSame('string', $tools['issue_read']['inputSchema']['properties']['ticket']['type']);
        foreach (['assign_copilot_to_issue', 'create_pull_request_with_copilot', 'request_copilot_review', 'get_copilot_job_status', 'delete_repository'] as $hidden) {
            $this->assertFalse($tools->has($hidden), "{$hidden} must not be offered by default");
        }
    }

    public function test_an_admin_can_switch_a_tool_off_or_a_copilot_tool_on_and_a_disabled_app_offers_nothing(): void
    {
        $this->pluginApp->update(['tools' => ['issue_read' => false, 'get_copilot_job_status' => true, 'delete_repository' => true]]);
        $names = collect($this->gatewayRpc('tools/list')->json('result.tools'))->pluck('name');
        $this->assertFalse($names->contains('issue_read'));
        $this->assertTrue($names->contains('get_copilot_job_status'));
        $this->assertFalse($names->contains('delete_repository'), 'a blocked tool stays blocked');

        $this->pluginApp->update(['is_enabled' => false]);
        $this->assertSame([], $this->gatewayRpc('tools/list')->json('result.tools'));
    }

    // ---- reads ---------------------------------------------------------------------------------------

    public function test_a_read_goes_to_githubs_read_only_address_as_that_person_and_is_recorded(): void
    {
        $this->fakeGitHub('two open issues');

        $response = $this->callTool('list_issues', ['owner' => 'octo', 'repo' => 'sandbox']);

        $response->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.content.0.text', 'two open issues');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.githubcopilot.com/mcp/readonly' && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertNotSent(fn (Request $r) => $r->url() === 'https://api.githubcopilot.com/mcp/');
        $action = PluginAction::sole();
        $this->assertSame(['read', 'done', 'list_issues', $this->user->id, $this->conversation->id], [$action->kind, $action->status, $action->tool, $action->user_id, $action->conversation_id]);
        $this->assertNull($action->arguments);
        $this->assertNotNull($this->connection->fresh()->last_used_at);
        $entry = AuditEntry::where('action', 'plugin.github.read')->sole();
        $this->assertSame($this->user->id, $entry->actor_id);
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('audit_entries')->get()));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_a_long_result_is_cut_and_marked(): void
    {
        $this->fakeGitHub(str_repeat('a', 30500));

        $text = $this->callTool('get_file_contents', ['owner' => 'o', 'repo' => 'r'])->json('result.content.0.text');

        $this->assertStringContainsString('[Shortened:', $text);
        $this->assertLessThan(30200, mb_strlen($text));
    }

    public function test_a_refused_token_marks_the_connection_for_reconnecting_and_says_nothing_more(): void
    {
        $this->fakeGitHub(status: 401);

        $this->assertRefused($this->callTool('get_me'));

        $this->assertSame('needs_reconnect', $this->connection->fresh()->status);
        $this->assertSame('failed', PluginAction::sole()->status);
        $this->assertRefused($this->callTool('get_me')); // no longer connected
    }

    // ---- every way of being refused ------------------------------------------------------------------

    public function test_a_read_the_app_reports_an_error_for_keeps_the_apps_own_words_so_the_cause_can_be_found(): void
    {
        Http::fake(function (Request $request) {
            return match ($request['method']) {
                'initialize' => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])."\n\n", 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 's1']),
                'notifications/initialized' => Http::response('', 202),
                default => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'Validation failed: the argument combination is not allowed']], 'isError' => true]])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
            };
        });

        $this->callTool('get_me')->assertOk()->assertJsonPath('result.isError', true);

        $action = PluginAction::where('tool', 'get_me')->sole();
        $this->assertSame('failed', $action->status);
        $this->assertSame('GitHub reported an error: Validation failed: the argument combination is not allowed', $action->result_summary);
    }

    public function test_every_refusal_gives_the_same_answer_and_calls_nobody(): void
    {
        $other = User::factory()->role(Role::EMPLOYEE)->create();
        $this->assertRefused($this->callTool('get_me', [], 'tk-'.str_repeat('x', 20)));                         // unknown ticket
        $this->assertRefused($this->callTool('get_me', [], 'garbage'));                                          // not even a ticket shape
        $this->assertRefused($this->callTool('get_me', [], PluginTicket::issue($other, $this->conversation, 'r'))); // another person's ticket on this conversation
        $this->conversation->update(['plugins' => null]);
        $this->assertRefused($this->callTool('get_me'));                                                          // plugin off for this chat
        $this->conversation->update(['plugins' => ['github']]);
        $this->pluginApp->update(['agent_ids' => [$this->agent->id + 99]]);
        $this->assertRefused($this->callTool('get_me'));                                                          // chatbot not allowed
        $this->pluginApp->update(['agent_ids' => [$this->agent->id], 'is_enabled' => false]);
        $this->assertRefused($this->callTool('get_me'));                                                          // app switched off
        $this->pluginApp->update(['is_enabled' => true]);
        $this->connection->update(['status' => 'needs_reconnect']);
        $this->assertRefused($this->callTool('get_me'));                                                          // not connected
        $this->connection->update(['status' => 'active']);
        $this->assertRefused($this->callTool('delete_repository', ['owner' => 'o', 'repo' => 'r']), 'That tool is not available for this request.');            // blocked
        $this->assertRefused($this->callTool('no_such_tool'), 'That tool is not available for this request.');                                                    // not in the snapshot
        $this->pluginApp->update(['tools' => ['get_me' => false]]);
        $this->assertRefused($this->callTool('get_me'));                                                          // switched off by an admin
        $this->user->forceFill(['is_active' => false])->save();
        $this->pluginApp->update(['tools' => null]);
        $this->assertRefused($this->callTool('get_me'));                                                          // deactivated account

        Http::assertNothingSent();
        $this->assertSame(0, PluginAction::count());
    }

    public function test_a_ticket_stops_working_after_its_lifetime(): void
    {
        $this->fakeGitHub();
        $this->callTool('get_me')->assertJsonPath('result.isError', false);

        $this->travel(31)->minutes();

        $this->assertRefused($this->callTool('get_me'));
    }

    public function test_a_conversation_of_someone_else_cannot_be_reached_with_my_ticket(): void
    {
        $other = User::factory()->role(Role::EMPLOYEE)->create();
        $theirs = Conversation::create(['user_id' => $other->id, 'agent_id' => $this->agent->id, 'title' => 'Private', 'plugins' => ['github']]);
        PluginConnection::factory()->create(['user_id' => $other->id]);

        $this->assertRefused($this->callTool('get_me', [], PluginTicket::issue($this->user, $theirs, 'r')));
    }

    // ---- changes only wait --------------------------------------------------------------------------

    public function test_a_change_is_only_recorded_as_pending_and_github_is_not_called(): void
    {
        $arguments = ['method' => 'create', 'owner' => 'octo', 'repo' => 'sandbox', 'title' => 'Fix the header'];

        $response = $this->callTool('issue_write', $arguments);

        $response->assertOk()->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('waiting for the person to approve', $response->json('result.content.0.text'));
        Http::assertNothingSent();
        $action = PluginAction::sole();
        $this->assertSame(['write', 'pending', 'issue_write'], [$action->kind, $action->status, $action->tool]);
        $this->assertStringContainsString('octo/sandbox', $action->summary);
        $this->assertSame($arguments, $action->arguments, 'the exact arguments are kept, without the ticket');
        $this->assertTrue($action->expires_at->isFuture());
        $this->assertStringNotContainsString('Fix the header', (string) DB::table('plugin_actions')->value('arguments'), 'stored encrypted');
        $this->assertSame(1, AuditEntry::where('action', 'plugin.github.proposed')->count());
    }

    public function test_the_same_change_asked_twice_in_one_answer_is_one_pending_action(): void
    {
        $arguments = ['method' => 'create', 'owner' => 'octo', 'repo' => 'sandbox', 'title' => 'Once'];

        $this->callTool('issue_write', $arguments);
        $this->callTool('issue_write', array_reverse($arguments, true));

        $this->assertSame(1, PluginAction::count());
    }

    public function test_too_many_waiting_changes_are_refused(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->callTool('issue_write', ['method' => 'create', 'owner' => 'o', 'repo' => 'r', 'title' => "t{$i}"]);
        }

        $this->callTool('issue_write', ['method' => 'create', 'owner' => 'o', 'repo' => 'r', 'title' => 'eleventh'])
            ->assertJsonPath('result.isError', true);
        $this->assertSame(10, PluginAction::count());
    }
}
