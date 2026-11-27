<?php

namespace Tests\Feature;

use App\Ai\GeneratedFiles;
use App\Ai\StreamTransport;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PluginAction;
use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginTicket;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** Switching GitHub on for a chat: who may, what the AI is told (the ticket), and when it is never told. */
class PluginChatTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const CHAT = 'general.example.com/p/general/v1/chat/completions';

    private Agent $agent;

    private User $user;

    private string $cookie;

    private PluginApp $pluginApp;

    private PluginConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        config(['services.hermes.url' => null, 'services.hermes.key' => null]);
        $this->agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        $this->user = $this->makeUser(Role::EMPLOYEE);
        [, $this->cookie] = $this->signIn($this->user);
        $this->pluginApp = PluginApp::factory()->enabledFor($this->agent->id)->create(['oauth_client_id' => 'Ov23x', 'oauth_client_secret' => 's']);
        $this->connection = PluginConnection::factory()->create(['user_id' => $this->user->id]);
        Http::preventStrayRequests();
        Http::fake([self::CHAT => Http::response(['model' => 'x', 'choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5]])]);
    }

    /** The hidden instructions of the Nth chat request to Hermes (1 = the first). */
    private function systemMessage(int $nth = 1): string
    {
        $requests = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/chat/completions'))->values();

        return collect($requests[$nth - 1][0]['messages'])->firstWhere('role', 'system')['content'];
    }

    private function ticketIn(string $system): ?string
    {
        return preg_match('/\b(tk-[a-z2-9]{20})\b/', $system, $m) ? $m[1] : null;
    }

    private function send(array $extra = [], ?string $cookie = null)
    {
        return $this->device($cookie ?? $this->cookie)->postJson('/api/chat', $extra + ['message' => 'Show my open issues']);
    }

    // ---- switching it on -----------------------------------------------------------------------------

    public function test_the_toggle_is_saved_with_the_chat_and_the_ticket_follows_the_company_rules(): void
    {
        $r = $this->send(['plugins' => ['github']])->assertOk()->assertJsonPath('data.conversation.plugins', ['github']);

        $conversation = Conversation::sole();
        $this->assertSame(['github'], $conversation->plugins);
        $system = $this->systemMessage();
        $ticket = $this->ticketIn($system);
        $this->assertNotNull($ticket, 'the instructions carry a ticket');
        $this->assertGreaterThan(strpos($system, GeneratedFiles::BLOCK), strpos($system, 'GitHub is switched on'), 'company rules come first');
        $found = PluginTicket::resolve($ticket);
        $this->assertSame([$this->user->id, $conversation->id], [$found['user_id'], $found['conversation_id']]);
        $this->assertStringStartsWith('chat-', $found['request_id']);
        $this->assertStringNotContainsString($this->connection->access_token, $system);
        $this->assertSame($r->json('data.conversation.id'), $conversation->id);
    }

    public function test_a_chat_without_the_plugin_is_exactly_what_it_was_before(): void
    {
        $this->send()->assertOk()->assertJsonPath('data.conversation.plugins', []);

        $this->assertSame(GeneratedFiles::autoInstruction(), $this->systemMessage());
        $this->assertNull(Conversation::sole()->plugins);
    }

    public function test_a_later_message_keeps_the_choice_unless_it_says_otherwise_and_each_answer_gets_its_own_ticket(): void
    {
        $id = $this->send(['plugins' => ['github']])->json('data.conversation.id');

        $this->send(['conversation_id' => $id])->assertOk();
        $this->assertNotNull($this->ticketIn($this->systemMessage(2)), 'absent key: the chat keeps GitHub on');
        $this->assertNotSame($this->ticketIn($this->systemMessage(1)), $this->ticketIn($this->systemMessage(2)));

        $this->send(['conversation_id' => $id, 'plugins' => []])->assertOk();
        $this->assertNull($this->ticketIn($this->systemMessage(3)));
        $this->assertNull(Conversation::find($id)->plugins);
    }

    public function test_regenerating_an_answer_makes_a_fresh_ticket(): void
    {
        $id = $this->send(['plugins' => ['github']])->json('data.conversation.id');

        $this->device($this->cookie)->postJson("/api/conversations/{$id}/regenerate")->assertOk();

        $first = $this->ticketIn($this->systemMessage(1));
        $second = $this->ticketIn($this->systemMessage(2));
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    // ---- who may switch it on ------------------------------------------------------------------------

    public function test_it_cannot_be_switched_on_unless_the_app_is_on_for_this_chatbot_and_the_person_is_connected(): void
    {
        $this->connection->update(['status' => 'needs_reconnect']);
        $this->send(['plugins' => ['github']])->assertStatus(422)->assertJsonPath('error.code', 'plugin_not_available');
        $this->connection->update(['status' => 'active']);

        $this->pluginApp->update(['is_enabled' => false]);
        $this->send(['plugins' => ['github']])->assertStatus(422);
        $this->pluginApp->update(['is_enabled' => true, 'agent_ids' => [$this->agent->id + 50]]);
        $this->send(['plugins' => ['github']])->assertStatus(422);
        $this->pluginApp->update(['agent_ids' => [$this->agent->id]]);

        $this->send(['plugins' => ['jira']])->assertStatus(422);
        $this->send(['plugins' => [['github']]])->assertStatus(422);
        PluginConnection::where('user_id', $this->user->id)->delete();
        $this->send(['plugins' => ['github']])->assertStatus(422);

        $this->assertSame(0, Conversation::count(), 'a refused request creates no conversation');
        Http::assertNothingSent();
    }

    public function test_the_ticket_is_not_made_if_the_login_or_the_app_went_away_after_switching_it_on(): void
    {
        $id = $this->send(['plugins' => ['github']])->json('data.conversation.id');

        $this->connection->update(['status' => 'needs_reconnect']);
        $this->send(['conversation_id' => $id])->assertOk();
        $this->assertNull($this->ticketIn($this->systemMessage(2)));

        $this->connection->update(['status' => 'active']);
        $this->pluginApp->update(['is_enabled' => false]);
        $this->send(['conversation_id' => $id])->assertOk();
        $this->assertNull($this->ticketIn($this->systemMessage(3)));
    }

    public function test_no_ticket_for_a_strict_file_format_request(): void
    {
        $this->send(['plugins' => ['github'], 'output_format' => 'csv']);

        $this->assertNull($this->ticketIn($this->systemMessage()));
    }

    public function test_no_ticket_while_a_super_admin_is_viewing_as_the_employee(): void
    {
        $id = $this->send(['plugins' => ['github']])->json('data.conversation.id');
        [, $superCookie] = $this->signIn($this->makeUser(Role::SUPER_ADMIN));
        $this->device($superCookie)->postJson("/api/users/{$this->user->id}/impersonate")->assertOk();

        $this->device($superCookie)->postJson('/api/chat', ['message' => 'What can you see in my GitHub?', 'conversation_id' => $id])->assertOk();

        $this->assertNull($this->ticketIn($this->systemMessage(2)));
    }

    public function test_a_streamed_answer_works_with_hermes_tool_progress_events_and_carries_the_ticket(): void
    {
        $sent = null;
        $this->mock(StreamTransport::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('read')->once()->andReturnUsing(function ($url, $headers, $payload, $timeout, $receive) use (&$sent) {
                $sent = $payload;
                $receive('data: '.json_encode(['id' => 'c1', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant'], 'finish_reason' => null]]])."\n\n");
                $receive("event: hermes.tool.progress\ndata: ".json_encode(['tool' => 'mcp__thryve_plugins__list_issues', 'emoji' => 'x', 'label' => 'sandbox', 'toolCallId' => 'toolu_1', 'status' => 'running'])."\n\n");
                $receive("event: hermes.tool.progress\ndata: ".json_encode(['tool' => 'mcp__thryve_plugins__list_issues', 'toolCallId' => 'toolu_1', 'status' => 'completed'])."\n\n");
                $receive('data: '.json_encode(['id' => 'c1', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => 'You have two open issues.'], 'finish_reason' => null]]])."\n\n");
                $receive('data: '.json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]])."\n\n");
                $receive('data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5]])."\n\n");
                $receive("data: [DONE]\n\n");

                return false;
            });
        });

        $this->device($this->cookie)->postJson('/api/chat', ['message' => 'Show my open issues', 'plugins' => ['github']], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()])->assertOk()->streamedContent();

        $this->assertSame('You have two open issues.', Message::where('role', 'assistant')->sole()->content);
        $this->assertNotNull($this->ticketIn($sent['messages'][0]['content']));
    }

    // ---- what the page can read -----------------------------------------------------------------------

    public function test_the_page_lists_a_plugin_only_when_it_is_usable_with_that_chatbot(): void
    {
        $this->device($this->cookie)->getJson('/api/chat/plugins?agent_id='.$this->agent->id)->assertOk()->assertJsonPath('data.plugins.0.slug', 'github');

        $this->connection->update(['status' => 'needs_reconnect']);
        $this->device($this->cookie)->getJson('/api/chat/plugins?agent_id='.$this->agent->id)->assertOk()->assertJsonPath('data.plugins', []);

        $other = Agent::create(['name' => 'Coach', 'slug' => 'coach', 'base_url' => 'https://coach.example.com', 'profile' => 'coach', 'outbound_key' => 'key-for-coach-1234567890', 'outbound_fingerprint' => 'efgh5678']);
        $this->device($this->cookie)->getJson('/api/chat/plugins?agent_id='.$other->id)->assertStatus(403);
        $this->device(null)->getJson('/api/chat/plugins?agent_id=1')->assertStatus(401);
    }

    public function test_the_conversation_shows_its_waiting_changes_only_to_its_owner_and_drops_expired_ones(): void
    {
        $id = $this->send(['plugins' => ['github']])->json('data.conversation.id');
        $waiting = PluginAction::factory()->create(['user_id' => $this->user->id, 'conversation_id' => $id]);
        $expired = PluginAction::factory()->create(['user_id' => $this->user->id, 'conversation_id' => $id, 'expires_at' => now()->subMinute()]);
        PluginAction::factory()->create(['user_id' => $this->user->id, 'conversation_id' => $id, 'status' => 'done']);

        $r = $this->device($this->cookie)->getJson("/api/conversations/{$id}")->assertOk();

        $r->assertJsonPath('data.plugins', ['github']);
        $this->assertSame([$waiting->id], array_column($r->json('data.pending_actions'), 'id'));
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertNull($expired->fresh()->arguments);
        $this->assertSame(['method' => 'create', 'owner' => 'octo', 'repo' => 'sandbox', 'title' => 'Test'], $r->json('data.pending_actions.0.arguments'), 'the owner sees exactly what they would approve');
        $this->assertStringNotContainsString('tk-', json_encode($r->json('data.pending_actions')));

        [, $otherCookie] = $this->signIn($this->makeUser(Role::EMPLOYEE));
        $this->device($otherCookie)->getJson("/api/conversations/{$id}")->assertStatus(404);
    }
}
