<?php

namespace Tests\Feature;

use App\Ai\AgentService;
use App\Models\Agent;
use App\Models\AgentCheck;
use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['services.hermes.url' => null, 'services.hermes.key' => null]); // the developer's .env must not leak into tests
    }

    private function sa(): string
    {
        return $this->cookieFor(Role::SUPER_ADMIN);
    }

    private function make(string $cookie, array $o = [])
    {
        return $this->device($cookie)->postJson('/api/agents', $o + ['name' => 'General Assistant', 'base_url' => 'https://ai.example.com', 'profile' => 'thryve-bot']);
    }

    private function models(array $ids = ['thryve-bot']): array
    {
        return ['object' => 'list', 'data' => array_map(fn ($id) => ['id' => $id, 'object' => 'model'], $ids)];
    }

    private function completion(string $text = 'pong'): array
    {
        return ['model' => 'thryve-bot', 'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 4]];
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_a_wrong_form_entry_says_what_to_type_instead(): void
    {
        $sa = $this->sa();
        $r = $this->make($sa, ['name' => '', 'base_url' => 'ftp://not-allowed.example', 'profile' => 'Bad Profile!'])->assertStatus(422);

        $this->assertSame('Enter a name for the agent.', $r->json('error.details.name.0'));
        $this->assertStringContainsString('starts with https://', $r->json('error.details.base_url.0'));
        $this->assertStringContainsString('lowercase letters', $r->json('error.details.profile.0'));
        $this->assertStringNotContainsString('base url field', json_encode($r->json()), 'no internal field names in what people read');
    }

    public function test_only_a_super_admin_can_change_agents_and_admins_can_look(): void
    {
        $sa = $this->sa();
        $id = $this->make($sa)->json('data.id');
        $writes = [['post', '/api/agents'], ['patch', "/api/agents/{$id}"], ['delete', "/api/agents/{$id}"], ['post', "/api/agents/{$id}/test"], ['post', "/api/agents/{$id}/rotate"],
            ['post', "/api/agents/{$id}/rotate/activate"], ['delete', "/api/agents/{$id}/rotate"], ['post', "/api/agents/{$id}/revoke"], ['post', "/api/agents/{$id}/inbound-key"]];
        $reads = [['get', '/api/agents'], ['get', "/api/agents/{$id}/checks"]];

        foreach (array_merge($reads, $writes) as [$m, $url]) {
            $this->device(null)->json($m, $url, [])->assertStatus(401);
            $this->device($this->cookieFor(Role::EMPLOYEE))->json($m, $url, [])->assertStatus(403);
        }
        $admin = $this->cookieFor(Role::ADMIN);
        foreach ($reads as [$m, $url]) {
            $this->device($admin)->json($m, $url)->assertOk();
        }
        foreach ($writes as [$m, $url]) {
            $this->device($admin)->json($m, $url, ['name' => 'x', 'base_url' => 'https://x.example.com'])->assertStatus(403);
        }
    }

    // ---- key generation --------------------------------------------------------------------------

    public function test_creating_an_agent_generates_keys_that_are_shown_once_and_stored_safely(): void
    {
        $sa = $this->sa();

        $r = $this->make($sa)->assertCreated();

        $out = $r->json('data.keys.outbound');
        $in = $r->json('data.keys.inbound');
        $this->assertStringStartsWith('thryve_', $out);
        $this->assertStringStartsWith('thryve_in_', $in);
        $this->assertGreaterThanOrEqual(70, strlen($out));
        $this->assertNotSame($out, $in);
        $agent = Agent::sole();
        $this->assertTrue($agent->is_default, 'the first agent becomes the default');
        $this->assertSame(AgentService::fingerprint($out), $agent->outbound_fingerprint);
        $raw = \DB::table('agents')->first();
        $this->assertStringNotContainsString($out, (string) $raw->outbound_key, 'encrypted at rest');
        $this->assertSame(hash('sha256', $in), $raw->inbound_key_hash);
        $this->assertStringNotContainsString($in, json_encode($raw));

        foreach (['/api/agents', "/api/agents/{$agent->id}/checks"] as $url) {
            $body = $this->device($sa)->getJson($url)->getContent();
            $this->assertStringNotContainsString($out, $body);
            $this->assertStringNotContainsString($in, $body);
        }
        $audit = json_encode(AuditEntry::all()->toArray());
        $this->assertStringNotContainsString($out, $audit);
        $this->assertStringNotContainsString($in, $audit);
    }

    public function test_every_agent_gets_different_keys(): void
    {
        $sa = $this->sa();
        $a = $this->make($sa)->json('data.keys');
        $b = $this->make($sa, ['name' => 'Second'])->json('data.keys');

        $this->assertNotSame($a['outbound'], $b['outbound']);
        $this->assertNotSame($a['inbound'], $b['inbound']);
        $this->assertSame(1, Agent::where('is_default', true)->count());
    }

    public function test_addresses_must_be_safe(): void
    {
        $sa = $this->sa();

        foreach (['http://ai.example.com', 'https://user:pass@ai.example.com', 'https://ai.example.com/?x=1', 'ftp://ai.example.com', 'https://169.254.169.254', 'not a url'] as $bad) {
            $this->make($sa, ['base_url' => $bad])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        $this->make($sa, ['name' => 'Local', 'base_url' => 'http://127.0.0.1:8642'])->assertCreated();
        $this->make($sa, ['name' => 'Bad profile', 'profile' => 'Has Spaces'])->assertStatus(422);
        $this->make($sa, ['name' => ''])->assertStatus(422);
    }

    // ---- connection test -------------------------------------------------------------------------

    public function test_a_working_agent_is_reported_working_and_the_key_is_sent(): void
    {
        Http::fake(['*' => Http::response($this->models())]);
        $sa = $this->sa();
        $made = $this->make($sa);
        $id = $made->json('data.id');

        $r = $this->device($sa)->postJson("/api/agents/{$id}/test")->assertOk();

        $r->assertJsonPath('data.check.ok', true)->assertJsonPath('data.agent.status', 'working');
        Http::assertSent(fn ($req) => $req->url() === 'https://ai.example.com/p/thryve-bot/v1/models' && $req->hasHeader('Authorization', 'Bearer '.$made->json('data.keys.outbound')));
        $this->assertNotNull($r->json('data.agent.last_checked_at'));
    }

    public static function failureCases(): array
    {
        return [
            'wrong key' => [401, [], 'unauthorized', 'key_rejected'],
            'profile gone' => [404, [], 'profile_missing', 'profile_missing'],
            'profile not listed' => [200, ['other-bot'], 'profile_missing', 'profile_missing'],
            'server error' => [502, [], 'unavailable', 'unreachable'],
            'rate limited' => [429, [], 'rate_limited', 'unreachable'],
            'web login page' => [302, [], 'login_page', 'wrong_address'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureCases')]
    public function test_failures_are_told_apart(int $http, array $models, string $category, string $status): void
    {
        Http::fake(['*' => Http::response($http === 200 ? $this->models($models) : ['detail' => 'secret upstream text'], $http)]);
        $sa = $this->sa();
        $id = $this->make($sa)->json('data.id');

        $r = $this->device($sa)->postJson("/api/agents/{$id}/test")->assertOk();

        $r->assertJsonPath('data.check.ok', false)->assertJsonPath('data.check.category', $category)->assertJsonPath('data.agent.status', $status);
        $this->assertStringNotContainsString('secret upstream text', $r->getContent());
    }

    public function test_an_unreachable_server_and_a_timeout_are_reported_without_the_address(): void
    {
        $sa = $this->sa();
        $id = $this->make($sa)->json('data.id');

        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to ai.example.com port 443'));
        $r = $this->device($sa)->postJson("/api/agents/{$id}/test")->assertOk();
        $r->assertJsonPath('data.check.category', 'unavailable')->assertJsonPath('data.agent.status', 'unreachable');
        $this->assertStringNotContainsString('ai.example.com', json_encode($r->json('data.check')));

    }

    public function test_a_timeout_is_its_own_category(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 8001 milliseconds'));

        $this->assertSame('timeout', app(\App\Ai\HermesClient::class)->probe('https://x.example.com', 'k')['category']);
    }

    public function test_a_slow_answer_is_flagged_slow(): void
    {
        $this->assertSame('slow', AgentService::statusFor(['ok' => true, 'category' => 'ok', 'latency_ms' => AgentService::SLOW_MS + 1]));
        $this->assertSame('working', AgentService::statusFor(['ok' => true, 'category' => 'ok', 'latency_ms' => 120]));
    }

    public function test_history_is_recorded_and_pruned(): void
    {
        Http::fake(['*' => Http::response($this->models())]);
        $sa = $this->sa();
        $id = $this->make($sa)->json('data.id');

        for ($i = 0; $i < AgentService::KEEP_CHECKS + 5; $i++) {
            app(AgentService::class)->test(Agent::find($id), null);
        }

        $this->assertSame(AgentService::KEEP_CHECKS, AgentCheck::where('agent_id', $id)->count());
        $this->device($sa)->getJson("/api/agents/{$id}/checks")->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.kind', 'ping');
    }

    public function test_nothing_runs_on_its_own(): void
    {
        Http::fake();
        $this->make($this->sa());

        $this->artisan('schedule:run')->run();

        Http::assertNothingSent();
        $this->assertSame(0, AgentCheck::count());
    }

    public function test_the_one_message_test_goes_through_the_ledger_as_background_usage(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);
        $sa = $this->sa();
        $made = $this->make($sa);
        $id = $made->json('data.id');

        $r = $this->device($sa)->postJson("/api/agents/{$id}/test", ['with_message' => true])->assertOk();

        $r->assertJsonPath('data.check.kind', 'chat')->assertJsonPath('data.check.ok', true)->assertJsonPath('data.check.tokens', 44);
        Http::assertSent(fn ($req) => $req->url() === 'https://ai.example.com/p/thryve-bot/v1/chat/completions' && $req->hasHeader('Authorization', 'Bearer '.$made->json('data.keys.outbound')) && $req['model'] === 'thryve-bot');
        $this->assertSame(1, UsageRecord::where('purpose', 'analysis')->count());
        $this->assertSame(0, UsageRecord::where('purpose', 'chat')->count());
    }

    // ---- rotation --------------------------------------------------------------------------------

    public function test_rotation_keeps_the_old_key_until_the_new_one_is_verified(): void
    {
        $sa = $this->sa();
        $made = $this->make($sa);
        $id = $made->json('data.id');
        $old = $made->json('data.keys.outbound');

        $r = $this->device($sa)->postJson("/api/agents/{$id}/rotate")->assertOk();
        $new = $r->json('data.keys.outbound');
        $this->assertNotSame($old, $new);
        $r->assertJsonPath('data.pending_fingerprint', AgentService::fingerprint($new))->assertJsonPath('data.key_fingerprint', AgentService::fingerprint($old));

        // The VPS still has the old key: verifying the new one fails and nothing changes.
        $accepted = $old; // what the VPS currently has installed
        Http::fake(function ($req) use (&$accepted) {
            return $req->hasHeader('Authorization', 'Bearer '.$accepted) ? Http::response($this->models()) : Http::response([], 401);
        });
        $this->device($sa)->postJson("/api/agents/{$id}/rotate/activate")->assertOk()->assertJsonPath('data.activated', false)->assertJsonPath('data.check.category', 'unauthorized');
        $agent = Agent::find($id);
        $this->assertSame($old, $agent->outbound_key);
        $this->assertNotNull($agent->pending_key);
        $this->device($sa)->postJson("/api/agents/{$id}/test")->assertJsonPath('data.agent.status', 'working');

        // The admin installs the new key on the VPS; now verification succeeds and the swap happens.
        $accepted = $new;
        $this->device($sa)->postJson("/api/agents/{$id}/rotate/activate")->assertOk()->assertJsonPath('data.activated', true)->assertJsonPath('data.agent.status', 'working');
        $agent->refresh();
        $this->assertSame($new, $agent->outbound_key);
        $this->assertNull($agent->pending_key);
        $this->assertSame(AgentService::fingerprint($new), $agent->outbound_fingerprint);
        $actions = AuditEntry::pluck('action')->all();
        $this->assertContains('agent.rotation_failed', $actions);
        $this->assertContains('agent.rotated', $actions);
        $this->assertStringNotContainsString($new, json_encode(AuditEntry::all()->toArray()));
    }

    public function test_activating_without_a_pending_key_and_cancelling_work_sensibly(): void
    {
        $sa = $this->sa();
        $id = $this->make($sa)->json('data.id');

        $this->device($sa)->postJson("/api/agents/{$id}/rotate/activate")->assertStatus(409)->assertJsonPath('error.code', 'no_pending_key');
        $this->device($sa)->postJson("/api/agents/{$id}/rotate");
        $this->device($sa)->deleteJson("/api/agents/{$id}/rotate")->assertOk()->assertJsonPath('data.pending_fingerprint', null);
        $this->assertNull(Agent::find($id)->pending_key);
    }

    // ---- revoke and remove -----------------------------------------------------------------------

    public function test_revoking_wipes_the_keys_and_blocks_both_directions(): void
    {
        Http::fake(['*' => Http::response($this->models())]);
        $sa = $this->sa();
        $made = $this->make($sa);
        $id = $made->json('data.id');
        $in = $made->json('data.keys.inbound');
        $this->device(null)->withHeader('Authorization', "Bearer {$in}")->getJson('/api/agent/ping')->assertOk();

        $this->device($sa)->postJson("/api/agents/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.has_key', false);

        $agent = Agent::find($id);
        $this->assertNull($agent->outbound_key);
        $this->assertNull($agent->inbound_key_hash);
        $this->device(null)->withHeader('Authorization', "Bearer {$in}")->getJson('/api/agent/ping')->assertStatus(401);
        Http::fake();
        $this->device($sa)->postJson("/api/agents/{$id}/test")->assertJsonPath('data.check.category', 'no_key')->assertJsonPath('data.agent.status', 'revoked');
        Http::assertNothingSent();
        // Chat must not fall back to anything else.
        $user = $this->cookieFor(Role::EMPLOYEE);
        $this->device($user)->postJson('/api/chat', ['message' => 'hi'])->assertStatus(403)->assertJsonPath('error.code', 'no_chatbot');
        Http::assertNothingSent();
    }

    public function test_default_agent_rules_and_removal_keep_history(): void
    {
        $sa = $this->sa();
        $a = $this->make($sa)->json('data.id');
        $b = $this->make($sa, ['name' => 'Second', 'base_url' => 'https://two.example.com'])->json('data.id');

        $this->device($sa)->patchJson("/api/agents/{$a}", ['is_enabled' => false])->assertStatus(422)->assertJsonPath('error.code', 'default_agent');
        $this->device($sa)->deleteJson("/api/agents/{$a}")->assertStatus(422)->assertJsonPath('error.code', 'default_agent');
        $this->device($sa)->patchJson("/api/agents/{$a}", ['is_default' => false])->assertStatus(422);

        $this->device($sa)->patchJson("/api/agents/{$b}", ['is_default' => true])->assertOk();
        $this->assertFalse(Agent::find($a)->is_default);
        $this->assertTrue(Agent::find($b)->is_default);

        $this->device($sa)->deleteJson("/api/agents/{$a}")->assertOk();
        $this->device($sa)->getJson('/api/agents')->assertJsonCount(1, 'data');
        $this->assertNotNull(Agent::find($a)->removed_at, 'kept so usage history still points at it');
        $this->device($sa)->getJson("/api/agents/{$a}/checks")->assertNotFound();
    }

    public function test_changing_the_address_resets_the_status(): void
    {
        Http::fake(['*' => Http::response($this->models())]);
        $sa = $this->sa();
        $id = $this->make($sa)->json('data.id');
        $this->device($sa)->postJson("/api/agents/{$id}/test");

        $this->device($sa)->patchJson("/api/agents/{$id}", ['profile' => 'other'])->assertOk()->assertJsonPath('data.status', 'never_tested')->assertJsonPath('data.url', 'https://ai.example.com/p/other');
        $this->device($sa)->patchJson("/api/agents/{$id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'never_tested');
    }

    // ---- the agent calling this system -------------------------------------------------------------

    public function test_the_inbound_key_lets_an_agent_ping_and_nothing_else(): void
    {
        $sa = $this->sa();
        $made = $this->make($sa);
        $in = $made->json('data.keys.inbound');
        $id = $made->json('data.id');

        $this->device(null)->withHeader('Authorization', "Bearer {$in}")->getJson('/api/agent/ping')->assertOk()->assertJsonPath('data.agent', 'general-assistant');
        $this->assertNotNull(Agent::find($id)->inbound_last_seen_at);
        $this->device($sa)->getJson('/api/agents')->assertJsonPath('data.0.inbound_fingerprint', AgentService::fingerprint($in));

        foreach (['/api/conversations', '/api/agents', '/api/users', '/api/auth/me', '/api/settings'] as $url) {
            $this->device(null)->withHeader('Authorization', "Bearer {$in}")->getJson($url)->assertStatus(401);
        }
        // The key it receives from us (outbound) is a different secret and does not work here.
        $this->device(null)->withHeader('Authorization', 'Bearer '.$made->json('data.keys.outbound'))->getJson('/api/agent/ping')->assertStatus(401);
    }

    public function test_bad_inbound_keys_get_a_plain_401(): void
    {
        foreach (['', 'Bearer', 'Bearer nonsense', 'Bearer thryve_in_'.str_repeat('0', 64), 'Bearer '.str_repeat('a', 500)] as $h) {
            $r = $this->device(null)->withHeader('Authorization', $h)->getJson('/api/agent/ping')->assertStatus(401);
            $this->assertSame('unauthenticated', $r->json('error.code'));
        }
    }

    public function test_a_new_inbound_key_replaces_the_old_one_at_once(): void
    {
        $sa = $this->sa();
        $made = $this->make($sa);
        $old = $made->json('data.keys.inbound');
        $id = $made->json('data.id');

        $new = $this->device($sa)->postJson("/api/agents/{$id}/inbound-key")->assertOk()->json('data.keys.inbound');

        $this->assertNotSame($old, $new);
        $this->device(null)->withHeader('Authorization', "Bearer {$old}")->getJson('/api/agent/ping')->assertStatus(401);
        $this->device(null)->withHeader('Authorization', "Bearer {$new}")->getJson('/api/agent/ping')->assertOk();
    }

    public function test_a_disabled_agent_cannot_call_in(): void
    {
        $sa = $this->sa();
        $a = $this->make($sa);
        $b = $this->make($sa, ['name' => 'Second']);
        $this->device($sa)->patchJson('/api/agents/'.$b->json('data.id'), ['is_enabled' => false])->assertOk();

        $this->device(null)->withHeader('Authorization', 'Bearer '.$b->json('data.keys.inbound'))->getJson('/api/agent/ping')->assertStatus(401);
        $this->device(null)->withHeader('Authorization', 'Bearer '.$a->json('data.keys.inbound'))->getJson('/api/agent/ping')->assertOk();
    }

    // ---- chat uses the default agent -----------------------------------------------------------------

    public function test_chat_uses_the_default_agents_address_key_and_profile_and_records_it(): void
    {
        Http::fake(['*' => Http::response($this->completion('Hello!'))]);
        $sa = $this->sa();
        $this->make($sa, ['name' => 'Other', 'base_url' => 'https://other.example.com', 'profile' => 'other-bot']);
        $main = $this->make($sa, ['name' => 'Main', 'base_url' => 'https://main.example.com', 'profile' => 'main-bot']);
        $this->device($sa)->patchJson('/api/agents/'.$main->json('data.id'), ['is_default' => true])->assertOk();
        $user = $this->cookieFor(Role::EMPLOYEE);

        $this->device($user)->postJson('/api/chat', ['message' => 'hi'])->assertOk()->assertJsonPath('data.reply.content', 'Hello!');

        Http::assertSent(fn ($req) => $req->url() === 'https://main.example.com/p/main-bot/v1/chat/completions'
            && $req->hasHeader('Authorization', 'Bearer '.$main->json('data.keys.outbound')) && $req['model'] === 'main-bot');
        $this->assertSame($main->json('data.id'), UsageRecord::where('purpose', 'chat')->sole()->agent_id);
    }

    public function test_without_any_agent_the_old_env_settings_still_work(): void
    {
        config(['services.hermes.url' => 'http://hermes.test/p/bot', 'services.hermes.key' => 'k-1234567890123456']);
        Http::fake(['*' => Http::response($this->completion())]);
        $user = $this->cookieFor(Role::EMPLOYEE);

        $this->device($user)->postJson('/api/chat', ['message' => 'hi'])->assertOk();

        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'http://hermes.test/p/bot/'));
        $this->assertNull(UsageRecord::where('purpose', 'chat')->sole()->agent_id);
    }

    public function test_employees_never_see_agent_details_in_chat_or_usage(): void
    {
        Http::fake(['*' => Http::response($this->completion())]);
        $sa = $this->sa();
        $made = $this->make($sa);
        $user = $this->cookieFor(Role::EMPLOYEE);

        $body = $this->device($user)->postJson('/api/chat', ['message' => 'hi'])->getContent().$this->device($user)->getJson('/api/usage/today')->getContent();

        $this->assertStringNotContainsString('ai.example.com', $body);
        $this->assertStringNotContainsString($made->json('data.keys.outbound'), $body);
        $this->assertStringNotContainsString('thryve-bot', $body);
    }

    // ---- import from .env ----------------------------------------------------------------------------------

    public function test_the_import_command_keeps_the_existing_key_and_splits_the_profile(): void
    {
        $this->makeUser(Role::SUPER_ADMIN);
        config(['services.hermes.url' => 'https://ai.example.com/p/thryve-bot', 'services.hermes.key' => 'existing-key-1234567890']);

        $this->artisan('thryve:agent-import')->assertSuccessful();

        $a = Agent::sole();
        $this->assertSame('https://ai.example.com', $a->base_url);
        $this->assertSame('thryve-bot', $a->profile);
        $this->assertSame('existing-key-1234567890', $a->outbound_key);
        $this->assertSame(AgentService::fingerprint('existing-key-1234567890'), $a->outbound_fingerprint);
        $this->artisan('thryve:agent-import')->assertFailed();
    }

    public function test_the_import_command_needs_the_old_settings(): void
    {
        $this->makeUser(Role::SUPER_ADMIN);

        $this->artisan('thryve:agent-import')->assertFailed();
        $this->assertSame(0, Agent::count());
    }
}
