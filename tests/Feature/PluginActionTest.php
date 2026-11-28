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
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** The person's decision on a change the AI proposed: approve runs it once as them, deny stops it, nothing else can. */
class PluginActionTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const TOKEN = 'gho_approver_token_9999';

    private const ARGUMENTS = ['method' => 'create', 'owner' => 'octo', 'repo' => 'sandbox', 'title' => 'Fix the header'];

    private User $user;

    private string $cookie;

    private Conversation $conversation;

    private PluginConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        $agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        PluginApp::factory()->enabledFor($agent->id)->create();
        $this->user = $this->makeUser(Role::EMPLOYEE);
        [, $this->cookie] = $this->signIn($this->user);
        $this->connection = PluginConnection::factory()->create(['user_id' => $this->user->id, 'access_token' => self::TOKEN]);
        $this->conversation = Conversation::create(['user_id' => $this->user->id, 'agent_id' => $agent->id, 'title' => 'Issues', 'plugins' => ['github']]);
    }

    private function pending(array $attrs = []): PluginAction
    {
        return PluginAction::factory()->create($attrs + ['user_id' => $this->user->id, 'conversation_id' => $this->conversation->id, 'request_id' => 'chat-r1', 'tool' => 'issue_write', 'arguments' => self::ARGUMENTS]);
    }

    private function fakeGitHub(string $text = 'Created https://github.com/octo/sandbox/issues/12', bool $isError = false): void
    {
        Http::fake(function (Request $request) use ($text, $isError) {
            return match ($request['method']) {
                'initialize' => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])."\n\n", 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 's1']),
                'notifications/initialized' => Http::response('', 202),
                default => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError]])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
            };
        });
    }

    private function toolCalls(): int
    {
        return Http::recorded(fn (Request $r) => ($r['method'] ?? null) === 'tools/call')->count();
    }

    private function approve(PluginAction $a, ?string $cookie = null)
    {
        return $this->device($cookie ?? $this->cookie)->postJson("/api/plugin-actions/{$a->id}/approve");
    }

    private function deny(PluginAction $a, ?string $cookie = null)
    {
        return $this->device($cookie ?? $this->cookie)->postJson("/api/plugin-actions/{$a->id}/deny");
    }

    // ---- approving -----------------------------------------------------------------------------------

    public function test_approving_runs_the_change_once_as_that_person_and_tells_the_chat(): void
    {
        $this->fakeGitHub();
        $action = $this->pending();

        $r = $this->approve($action)->assertOk()->assertJsonPath('data.action.status', 'done');

        Http::assertSent(fn (Request $q) => $q->url() === 'https://api.githubcopilot.com/mcp/' && $q->hasHeader('Authorization', 'Bearer '.self::TOKEN) && ($q['method'] ?? null) === 'tools/call'
            && $q['params']['name'] === 'issue_write' && (array) $q['params']['arguments'] == self::ARGUMENTS);
        Http::assertNotSent(fn (Request $q) => $q->url() === 'https://api.githubcopilot.com/mcp/readonly');
        $fresh = $action->fresh();
        $this->assertSame('done', $fresh->status);
        $this->assertNull($fresh->arguments);
        $this->assertNull(DB::table('plugin_actions')->value('arguments'));
        $this->assertNotNull($fresh->decided_at);
        $this->assertNotNull($fresh->executed_at);
        $this->assertStringContainsString('issues/12', $fresh->result_summary);
        $this->assertStringContainsString('you approved it', $r->json('data.message.content'));
        $this->assertSame($r->json('data.message.id'), $this->conversation->messages()->where('role', 'assistant')->sole()->id);
        $this->assertArrayNotHasKey('arguments', $r->json('data.action'));
        $this->assertSame(1, AuditEntry::where('action', 'plugin.github.approved')->count());
        $this->assertStringNotContainsString(self::TOKEN, $r->getContent());
    }

    public function test_a_change_can_only_be_decided_once(): void
    {
        $this->fakeGitHub();
        $action = $this->pending();

        $this->approve($action)->assertOk();
        $this->approve($action)->assertStatus(409)->assertJsonPath('error.code', 'not_pending');
        $this->deny($action)->assertStatus(409);

        $this->assertSame(1, $this->toolCalls());
        $this->assertSame('done', $action->fresh()->status);
    }

    public function test_denying_stops_the_change_and_it_can_not_be_approved_afterwards(): void
    {
        $this->fakeGitHub();
        $action = $this->pending();

        $r = $this->deny($action)->assertOk()->assertJsonPath('data.action.status', 'denied');

        $this->assertStringContainsString('you said no', $r->json('data.message.content'));
        $this->assertNull($action->fresh()->arguments);
        $this->approve($action)->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame(1, AuditEntry::where('action', 'plugin.github.denied')->count());
    }

    public function test_a_change_that_waited_too_long_expires_and_cannot_run(): void
    {
        $this->fakeGitHub();
        $action = $this->pending(['expires_at' => now()->subMinute()]);

        $this->approve($action)->assertStatus(409);

        $this->assertSame('expired', $action->fresh()->status);
        $this->assertNull($action->fresh()->arguments);
        Http::assertNothingSent();
        $this->deny($this->pending(['expires_at' => now()->subMinute()]))->assertStatus(409);
    }

    // ---- who may decide ------------------------------------------------------------------------------

    public function test_only_the_owner_can_decide_and_only_a_change_not_a_read(): void
    {
        $this->fakeGitHub();
        $action = $this->pending();
        [, $otherCookie] = $this->signIn($this->makeUser(Role::EMPLOYEE));

        $this->approve($action, $otherCookie)->assertStatus(404);
        $this->deny($action, $otherCookie)->assertStatus(404);
        $this->approve($this->pending(['kind' => 'read', 'status' => 'done']))->assertStatus(404);
        $this->device(null)->postJson("/api/plugin-actions/{$action->id}/approve")->assertStatus(401);

        $this->assertSame('pending', $action->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_super_admin_viewing_as_the_employee_cannot_approve_or_deny(): void
    {
        $this->fakeGitHub();
        $action = $this->pending();
        [, $superCookie] = $this->signIn($this->makeUser(Role::SUPER_ADMIN));
        $this->device($superCookie)->postJson("/api/users/{$this->user->id}/impersonate")->assertOk();

        $this->approve($action, $superCookie)->assertStatus(403)->assertJsonPath('error.code', 'not_while_viewing_as');
        $this->deny($action, $superCookie)->assertStatus(403);

        $this->assertSame('pending', $action->fresh()->status);
        Http::assertNothingSent();
    }

    // ---- when GitHub says no -------------------------------------------------------------------------

    public function test_a_github_failure_is_recorded_without_retrying(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $action = $this->pending();

        $r = $this->approve($action)->assertOk()->assertJsonPath('data.action.status', 'failed');

        $this->assertStringContainsString('500', $r->json('data.action.result_summary'));
        $this->assertStringContainsString('Not done in GitHub', $r->json('data.message.content'));
        $this->assertNull($action->fresh()->arguments);
        Http::assertSentCount(1);
    }

    public function test_a_refused_login_marks_the_connection_for_reconnecting(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->approve($this->pending())->assertOk()->assertJsonPath('data.action.status', 'failed');

        $this->assertSame('needs_reconnect', $this->connection->fresh()->status);
    }

    public function test_an_error_from_the_tool_itself_is_a_failed_change(): void
    {
        $this->fakeGitHub('Validation failed: title is required', true);

        $this->approve($this->pending())->assertOk()->assertJsonPath('data.action.status', 'failed')->assertJsonPath('data.action.result_summary', 'GitHub reported an error: Validation failed: title is required');
    }

    public function test_a_change_cannot_run_without_a_working_login(): void
    {
        $this->fakeGitHub();
        $this->connection->delete();
        $action = $this->pending();

        $this->approve($action)->assertOk()->assertJsonPath('data.action.status', 'failed');

        $this->assertSame(0, $this->toolCalls());
        $this->assertStringContainsString('not connected', $action->fresh()->result_summary);
    }
}
