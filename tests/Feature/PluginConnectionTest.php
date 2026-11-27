<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditEntry;
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

/** A person connecting their own GitHub account (OAuth with PKCE), seeing its state, and disconnecting. */
class PluginConnectionTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private PluginApp $pluginApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        $agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        $this->pluginApp = PluginApp::factory()->enabledFor($agent->id)->create(['oauth_client_id' => 'Ov23testclient', 'oauth_client_secret' => 'client-secret-xyz']);
    }

    private function fakeGitHubSignIn(string $token = 'gho_new_token_abc', string $login = 'octocat'): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => $token, 'scope' => 'read:org,read:user,repo', 'token_type' => 'bearer']),
            'api.github.com/user' => Http::response(['login' => $login]),
            'api.github.com/applications/*/grant' => Http::response('', 204),
        ]);
    }

    /** @return array{0: string, 1: User} cookie and employee */
    private function employee(): array
    {
        $user = $this->makeUser(Role::EMPLOYEE);
        [, $cookie] = $this->signIn($user);

        return [$cookie, $user];
    }

    /** Starts the sign-in and returns the state GitHub would send back, plus the address the browser was sent to. */
    private function start(string $cookie): array
    {
        $location = $this->device($cookie)->get('/api/plugins/github/connect')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return [$query['state'] ?? '', $location, $query];
    }

    private function finish(string $cookie, string $state, string $code = 'code-from-github')
    {
        return $this->device($cookie)->get('/api/plugins/github/callback?'.http_build_query(['code' => $code, 'state' => $state]));
    }

    // ---- the state of things ------------------------------------------------------------------------

    public function test_the_page_shows_availability_and_the_persons_own_connection_but_never_a_token(): void
    {
        [$cookie, $user] = $this->employee();
        PluginConnection::factory()->create(['user_id' => $user->id, 'external_login' => 'octocat', 'access_token' => 'gho_must_not_leak']);
        PluginConnection::factory()->create(['external_login' => 'someone-else', 'access_token' => 'gho_other_person']);

        $r = $this->device($cookie)->getJson('/api/plugins')->assertOk();

        $r->assertJsonPath('data.apps.0.available', true)->assertJsonPath('data.apps.0.connected', true)->assertJsonPath('data.apps.0.login', 'octocat')->assertJsonPath('data.apps.0.status', 'active');
        $this->assertStringNotContainsString('gho_', $r->getContent());
        $this->assertStringNotContainsString('someone-else', $r->getContent());
    }

    public function test_the_app_is_not_available_until_an_admin_has_switched_it_on_and_set_it_up(): void
    {
        [$cookie] = $this->employee();
        $this->pluginApp->update(['is_enabled' => false]);
        $this->device($cookie)->getJson('/api/plugins')->assertJsonPath('data.apps.0.available', false);
        $this->device($cookie)->get('/api/plugins/github/connect')->assertRedirect('/plugins?error=not_available');

        $this->pluginApp->update(['is_enabled' => true, 'oauth_client_secret' => null]);
        $this->device($cookie)->getJson('/api/plugins')->assertJsonPath('data.apps.0.available', false);
        $this->device($cookie)->get('/api/plugins/github/connect')->assertRedirect('/plugins?error=not_available');
    }

    public function test_signed_out_visitors_get_nothing(): void
    {
        $this->device(null)->getJson('/api/plugins')->assertStatus(401);
        $this->device(null)->getJson('/api/plugins/github/connect')->assertStatus(401);
        $this->device(null)->deleteJson('/api/plugins/github/connection')->assertStatus(401);
    }

    // ---- signing in ---------------------------------------------------------------------------------

    public function test_connecting_sends_the_browser_to_github_with_a_state_and_a_pkce_challenge(): void
    {
        [$cookie] = $this->employee();

        [$state, $location, $query] = $this->start($cookie);

        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        $this->assertSame('Ov23testclient', $query['client_id']);
        $this->assertSame('repo read:org read:user', $query['scope']);
        $this->assertSame(40, strlen($state));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(43, strlen($query['code_challenge']));
        $this->assertStringEndsWith('/api/plugins/github/callback', $query['redirect_uri']);
        $this->assertStringNotContainsString('client-secret-xyz', $location);
    }

    public function test_coming_back_from_github_stores_the_login_and_an_encrypted_token(): void
    {
        $this->fakeGitHubSignIn('gho_new_token_abc', 'octocat');
        [$cookie, $user] = $this->employee();
        [$state, , $query] = $this->start($cookie);

        $this->finish($cookie, $state)->assertRedirect('/plugins?connected=github');

        $connection = PluginConnection::where('user_id', $user->id)->sole();
        $this->assertSame(['octocat', 'active', 'gho_new_token_abc'], [$connection->external_login, $connection->status, $connection->access_token]);
        $this->assertNotNull($connection->connected_at);
        $this->assertStringNotContainsString('gho_new_token_abc', (string) DB::table('plugin_connections')->value('access_token'), 'stored encrypted');
        Http::assertSent(function (Request $r) use ($query) {
            return $r->url() === 'https://github.com/login/oauth/access_token' && $r['code'] === 'code-from-github' && $r['client_secret'] === 'client-secret-xyz'
                && strlen($r['code_verifier']) === 64 && rtrim(strtr(base64_encode(hash('sha256', $r['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge'];
        });
        $this->assertSame(1, AuditEntry::where('action', 'plugin.github.connected')->count());
        $this->assertStringNotContainsString('gho_new_token_abc', json_encode(DB::table('audit_entries')->get()));
    }

    public function test_connecting_again_replaces_the_old_login_and_clears_the_reconnect_flag(): void
    {
        $this->fakeGitHubSignIn('gho_second', 'octocat');
        [$cookie, $user] = $this->employee();
        PluginConnection::factory()->needsReconnect()->create(['user_id' => $user->id, 'access_token' => 'gho_old']);
        [$state] = $this->start($cookie);

        $this->finish($cookie, $state)->assertRedirect('/plugins?connected=github');

        $this->assertSame(1, PluginConnection::where('user_id', $user->id)->count());
        $this->assertSame(['gho_second', 'active'], [PluginConnection::where('user_id', $user->id)->first()->access_token, PluginConnection::where('user_id', $user->id)->first()->status]);
    }

    public function test_a_wrong_missing_or_reused_state_stores_nothing(): void
    {
        $this->fakeGitHubSignIn();
        [$cookie, $user] = $this->employee();

        $this->finish($cookie, 'no-sign-in-was-started')->assertStatus(400)->assertJsonPath('error.code', 'invalid_state');

        [$state] = $this->start($cookie);
        $this->finish($cookie, str_repeat('a', 40))->assertStatus(400);
        $this->finish($cookie, $state)->assertStatus(400); // the state was used up by the failed attempt: a start cannot be replayed

        [$state] = $this->start($cookie);
        $this->finish($cookie, $state)->assertRedirect('/plugins?connected=github');
        $this->finish($cookie, $state)->assertStatus(400); // and a finished one cannot be replayed either
        $this->assertSame(1, PluginConnection::where('user_id', $user->id)->count());
    }

    public function test_a_sign_in_started_by_someone_else_cannot_be_finished_by_another_person(): void
    {
        $this->fakeGitHubSignIn();
        [$cookieA] = $this->employee();
        [$cookieB] = $this->employee();
        [$stateA] = $this->start($cookieA);

        $this->finish($cookieB, $stateA)->assertStatus(400);

        $this->assertSame(0, PluginConnection::count());
    }

    public function test_cancelling_on_github_or_a_failed_exchange_ends_cleanly_with_nothing_stored(): void
    {
        [$cookie] = $this->employee();
        [$state] = $this->start($cookie);
        $this->device($cookie)->get('/api/plugins/github/callback?'.http_build_query(['error' => 'access_denied', 'state' => $state]))->assertRedirect('/plugins?error=denied');

        Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code'])]);
        [$state] = $this->start($cookie);
        $this->finish($cookie, $state)->assertRedirect('/plugins?error=failed');

        $this->assertSame(0, PluginConnection::count());
    }

    // ---- disconnecting ------------------------------------------------------------------------------

    public function test_disconnecting_deletes_the_login_asks_github_to_revoke_it_and_leaves_other_people_alone(): void
    {
        $this->fakeGitHubSignIn();
        [$cookie, $user] = $this->employee();
        PluginConnection::factory()->create(['user_id' => $user->id, 'access_token' => 'gho_mine']);
        $other = PluginConnection::factory()->create();

        $this->device($cookie)->deleteJson('/api/plugins/github/connection')->assertOk();

        $this->assertSame(0, PluginConnection::where('user_id', $user->id)->count());
        $this->assertTrue(PluginConnection::whereKey($other->id)->exists());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/applications/Ov23testclient/grant') && $r['access_token'] === 'gho_mine');
        $this->assertSame(1, AuditEntry::where('action', 'plugin.github.disconnected')->count());
        $this->device($cookie)->deleteJson('/api/plugins/github/connection')->assertOk(); // nothing to disconnect is fine
    }

    public function test_the_login_is_deleted_even_if_github_cannot_be_reached_to_revoke_it(): void
    {
        Http::fake(['api.github.com/applications/*/grant' => Http::response('down', 500)]);
        [$cookie, $user] = $this->employee();
        PluginConnection::factory()->create(['user_id' => $user->id]);

        $this->device($cookie)->deleteJson('/api/plugins/github/connection')->assertOk();

        $this->assertSame(0, PluginConnection::where('user_id', $user->id)->count());
    }

    // ---- viewing as ---------------------------------------------------------------------------------

    public function test_everything_is_closed_while_a_super_admin_is_viewing_as_the_employee(): void
    {
        $employee = $this->makeUser(Role::EMPLOYEE);
        PluginConnection::factory()->create(['user_id' => $employee->id, 'access_token' => 'gho_employee_private']);
        [, $superCookie] = $this->signIn($this->makeUser(Role::SUPER_ADMIN));
        $this->device($superCookie)->postJson("/api/users/{$employee->id}/impersonate")->assertOk();

        foreach (['getJson' => '/api/plugins', 'get' => '/api/plugins/github/connect', 'deleteJson' => '/api/plugins/github/connection'] as $method => $url) {
            $this->device($superCookie)->{$method}($url)->assertStatus(403);
        }
        $this->device($superCookie)->getJson('/api/plugins/github/callback?code=x&state=y')->assertStatus(403)->assertJsonPath('error.code', 'not_while_viewing_as');
        $this->assertSame(1, PluginConnection::where('user_id', $employee->id)->count());
    }
}
