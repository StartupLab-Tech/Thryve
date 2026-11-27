<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\PluginAction;
use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Models\Role;
use App\Models\User;
use App\Plugins\AbstractProvider;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginTicket;
use App\Plugins\Providers\GoogleGmailProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/**
 * Google Drive, Docs and Sheets are three plugins that sign in through one Google client: one shared callback, a refresh token that is
 * renewed when it runs out, and each product's own MCP server for its own tools.
 */
class GoogleProviderTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const KEY = 'thryve_plug_google_key';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const PRODUCTS = ['google-drive', 'google-docs', 'google-sheets', 'google-gmail'];

    /** The three that existed when Google was one plugin. */
    private const LEGACY = ['google-drive', 'google-docs', 'google-sheets'];

    private Agent $agent;

    private User $user;

    private Conversation $conversation;

    private string $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        PluginRegistry::forget();
        $this->agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        $this->user = $this->makeUser(Role::EMPLOYEE);
        PluginApp::gateway()->update(['gateway_key_hash' => hash('sha256', self::KEY)]);
        PluginApp::for('google', 'Google sign-in')->update(['oauth_client_id' => 'client-id', 'oauth_client_secret' => 'client-secret']);
        foreach (self::PRODUCTS as $slug) {
            PluginApp::for($slug)->update(['is_enabled' => true, 'agent_ids' => [$this->agent->id]]);
        }
        $this->conversation = Conversation::create(['user_id' => $this->user->id, 'agent_id' => $this->agent->id, 'title' => 'Files', 'plugins' => self::PRODUCTS]);
        $this->ticket = PluginTicket::issue($this->user, $this->conversation, 'chat-r1');
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(AbstractProvider::class, 'snapshots'))->setValue(null, []);
        PluginRegistry::forget();
        parent::tearDown();
    }

    private function connect(string $product = 'google-drive', string $access = 'ya29.first', ?string $refresh = '1//refresh', ?int $expiresInMinutes = 50): PluginConnection
    {
        return PluginConnection::factory()->create(['user_id' => $this->user->id, 'app_slug' => $product, 'access_token' => $access, 'refresh_token' => $refresh,
            'token_expires_at' => $expiresInMinutes === null ? null : now()->addMinutes($expiresInMinutes), 'external_login' => 'robi@gmail.com']);
    }

    /** One fake for Google: its token and MCP servers. $mcpAnswer may refuse a token; $tokenAnswer may refuse a refresh. */
    private function fakeGoogle(?callable $mcpAnswer = null, ?callable $tokenAnswer = null): void
    {
        Http::fake(function (Request $request) use ($mcpAnswer, $tokenAnswer) {
            return match ($request->url()) {
                self::TOKEN_URL => $tokenAnswer ? $tokenAnswer($request) : Http::response(['access_token' => 'ya29.renewed', 'expires_in' => 3599]),
                'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'robi@gmail.com']),
                'https://oauth2.googleapis.com/revoke' => Http::response(''),
                default => $mcpAnswer && ($refused = $mcpAnswer($request)) ? $refused : match ($request['method']) {
                    'initialize' => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])."\n\n", 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 's1']),
                    'notifications/initialized' => Http::response('', 202),
                    default => Http::response('data: '.json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'ok:'.$request['params']['name']]], 'isError' => false]])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
                },
            };
        });
    }

    private function gatewayRpc(string $method, array $params = [])
    {
        return $this->postJson('/api/plugins/gateway', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['Authorization' => 'Bearer '.self::KEY]);
    }

    private function callTool(string $tool, array $arguments = [])
    {
        return $this->gatewayRpc('tools/call', ['name' => $tool, 'arguments' => ['ticket' => $this->ticket] + $arguments]);
    }

    /** @return Collection<int, Request> */
    private function toolCalls(): Collection
    {
        return Http::recorded(fn (Request $r) => ($r['method'] ?? null) === 'tools/call')->map(fn ($pair) => $pair[0])->values();
    }

    // ---- signing in ----------------------------------------------------------------------------------

    public function test_each_product_asks_google_only_for_its_own_permissions_and_comes_back_to_one_callback(): void
    {
        [, $cookie] = $this->signIn($this->user);
        $scopes = [];
        foreach (self::PRODUCTS as $slug) {
            $location = $this->device($cookie)->get("/api/plugins/{$slug}/connect")->assertRedirect()->headers->get('Location');
            $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
            parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
            $this->assertSame(['offline', 'consent', 'S256', 'client-id'], [$q['access_type'], $q['prompt'], $q['code_challenge_method'], $q['client_id']]);
            $this->assertStringEndsWith('/api/plugins/google/callback', $q['redirect_uri'], 'the one address registered in Google');
            $scopes[$slug] = $q['scope'];
        }

        $this->assertStringContainsString('drive.file', $scopes['google-drive']);
        $this->assertStringNotContainsString('documents', $scopes['google-drive']);
        $this->assertStringNotContainsString('spreadsheets', $scopes['google-drive']);
        $this->assertStringContainsString('/auth/documents', $scopes['google-docs']);
        $this->assertStringNotContainsString('spreadsheets', $scopes['google-docs']);
        $this->assertStringContainsString('/auth/spreadsheets', $scopes['google-sheets']);
        $this->assertStringNotContainsString('documents', $scopes['google-sheets']);
    }

    public function test_coming_back_connects_the_product_that_was_started_and_keeps_the_refresh_token_encrypted(): void
    {
        $this->fakeGoogle(tokenAnswer: fn () => Http::response(['access_token' => 'ya29.signedin', 'refresh_token' => '1//signedin', 'expires_in' => 3599, 'scope' => 'openid email']));
        [, $cookie] = $this->signIn($this->user);
        $location = $this->device($cookie)->get('/api/plugins/google-docs/connect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->device($cookie)->get('/api/plugins/google/callback?'.http_build_query(['code' => 'abc', 'state' => $q['state']]))->assertRedirect('/plugins?connected=google-docs');

        $connection = PluginConnection::where('user_id', $this->user->id)->sole();
        $this->assertSame('google-docs', $connection->app_slug);
        $this->assertSame('robi@gmail.com', $connection->external_login);
        $this->assertSame('1//signedin', $connection->refresh_token);
        $this->assertEqualsWithDelta(now()->addSeconds(3599)->timestamp, $connection->token_expires_at->timestamp, 10);
        $raw = DB::table('plugin_connections')->where('id', $connection->id)->first();
        $this->assertStringNotContainsString('1//signedin', (string) $raw->refresh_token);
        $this->assertStringNotContainsString('ya29.signedin', (string) $raw->access_token);
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        Http::assertSent(fn (Request $r) => $r->url() === self::TOKEN_URL && $r['client_id'] === 'client-id' && $r['client_secret'] === 'client-secret' && $r['grant_type'] === 'authorization_code');
    }

    public function test_the_shared_callback_is_refused_for_a_different_plugins_address(): void
    {
        [, $cookie] = $this->signIn($this->user);
        $location = $this->device($cookie)->get('/api/plugins/google-drive/connect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->device($cookie)->get('/api/plugins/github/callback?'.http_build_query(['code' => 'abc', 'state' => $q['state']]))->assertStatus(400)->assertJsonPath('error.code', 'invalid_state');

        $this->assertSame(0, PluginConnection::count());
    }

    public function test_the_login_is_revoked_at_google_only_when_the_last_product_is_disconnected(): void
    {
        $this->fakeGoogle();
        $this->connect('google-drive', 'ya29.drive');
        $this->connect('google-docs', 'ya29.docs');
        [, $cookie] = $this->signIn($this->user);

        $this->device($cookie)->deleteJson('/api/plugins/google-drive/connection')->assertOk();

        Http::assertNotSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke');
        $this->assertSame(['google-docs'], PluginConnection::pluck('app_slug')->all(), 'Docs keeps working');

        $this->device($cookie)->deleteJson('/api/plugins/google-docs/connection')->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'ya29.docs');
        $this->assertSame(0, PluginConnection::count());
    }

    // ---- what people see -----------------------------------------------------------------------------

    public function test_the_plugins_page_lists_the_three_products_available_once_the_shared_client_is_set(): void
    {
        [, $cookie] = $this->signIn($this->user);

        $apps = collect($this->device($cookie)->getJson('/api/plugins')->assertOk()->json('data.apps'))->keyBy('slug');

        foreach (self::PRODUCTS as $slug) {
            $this->assertTrue($apps[$slug]['available'], $slug);
        }
        $this->assertSame(['Google Drive', 'Google Docs', 'Google Sheets'], [$apps['google-drive']['name'], $apps['google-docs']['name'], $apps['google-sheets']['name']]);
        $this->assertArrayNotHasKey('google', $apps->all(), 'the shared sign-in is not a plugin people connect');

        PluginApp::for('google')->update(['oauth_client_secret' => null]);
        $apps = collect($this->device($cookie)->getJson('/api/plugins')->json('data.apps'))->keyBy('slug');
        $this->assertFalse($apps['google-drive']['available']);
    }

    public function test_the_chat_offers_only_the_products_this_person_connected(): void
    {
        $this->connect('google-drive');
        $this->connect('google-sheets', 'ya29.sheets');
        [, $cookie] = $this->signIn($this->user);

        $this->device($cookie)->getJson('/api/chat/plugins?agent_id='.$this->agent->id)->assertOk()
            ->assertJsonPath('data.plugins', [['slug' => 'google-drive', 'name' => 'Google Drive'], ['slug' => 'google-sheets', 'name' => 'Google Sheets']]);
    }

    // ---- the admin page ------------------------------------------------------------------------------

    public function test_the_admin_page_has_one_google_client_form_for_all_three_and_each_product_its_own_card(): void
    {
        [, $cookie] = $this->signIn($this->makeUser(Role::SUPER_ADMIN));

        $r = $this->device($cookie)->getJson('/api/plugin-apps')->assertOk();

        $shared = $r->json('data.shared_oauth');
        $this->assertCount(1, $shared);
        $this->assertSame('google', $shared[0]['slug']);
        $this->assertSame('client-id', $shared[0]['oauth_client_id']);
        $this->assertTrue($shared[0]['oauth_secret_set']);
        $this->assertSame(['Google Drive', 'Google Docs', 'Google Sheets', 'Gmail'], $shared[0]['used_by']);
        $this->assertStringEndsWith('/api/plugins/google/callback', $shared[0]['callback_url']);
        $this->assertStringNotContainsString('client-secret', $r->getContent());
        $apps = collect($r->json('data.apps'))->keyBy('slug');
        $this->assertSame('google', $apps['google-docs']['oauth_shared']);
        $this->assertNull($apps['github']['oauth_shared']);
        $this->assertSame(12, count($apps['google-drive']['tools']));
        $this->assertSame(2, count($apps['google-docs']['tools']));
        $this->assertSame(6, count($apps['google-sheets']['tools']));
    }

    public function test_saving_the_client_from_any_google_card_or_from_the_shared_form_saves_the_one_shared_client(): void
    {
        [, $cookie] = $this->signIn($this->makeUser(Role::SUPER_ADMIN));

        $this->device($cookie)->putJson('/api/plugin-apps/google-docs/oauth', ['client_id' => 'from-docs', 'client_secret' => 's1'])->assertOk();
        $this->assertSame(['from-docs', 's1'], [PluginApp::for('google')->oauth_client_id, PluginApp::for('google')->oauth_client_secret]);

        $this->device($cookie)->putJson('/api/plugin-apps/google/oauth', ['client_id' => 'from-shared', 'client_secret' => ''])->assertOk();
        $this->assertSame(['from-shared', 's1'], [PluginApp::for('google')->oauth_client_id, PluginApp::for('google')->oauth_client_secret], 'an empty secret keeps the saved one');
        $this->assertNull(PluginApp::for('google-docs')->oauth_client_id, 'a product keeps no copy of its own');

        $this->device($cookie)->putJson('/api/plugin-apps/nothing/oauth', ['client_id' => 'x'])->assertNotFound();
    }

    public function test_each_product_is_switched_on_and_given_to_chatbots_on_its_own(): void
    {
        [, $cookie] = $this->signIn($this->makeUser(Role::SUPER_ADMIN));

        $this->device($cookie)->patchJson('/api/plugin-apps/google-sheets', ['is_enabled' => false])->assertOk();

        $this->assertFalse(PluginApp::for('google-sheets')->is_enabled);
        $this->assertTrue(PluginApp::for('google-drive')->is_enabled);
        $names = collect($this->gatewayRpc('tools/list')->json('result.tools'))->pluck('name');
        $this->assertFalse($names->contains('google_sheets_get_values'));
        $this->assertTrue($names->contains('google_drive_search_files'));
        $this->assertTrue($names->contains('google_docs_read_doc'));
    }

    // ---- using a tool --------------------------------------------------------------------------------

    public function test_a_read_goes_to_the_products_own_server_under_googles_own_tool_name(): void
    {
        $this->fakeGoogle();
        $this->connect('google-drive');

        $this->callTool('google_drive_search_files', ['query' => 'budget'])->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.content.0.text', 'ok:search_files');

        $call = $this->toolCalls()->sole();
        $this->assertSame('https://drivemcp.googleapis.com/mcp/v1self', $call->url());
        $this->assertSame('search_files', $call['params']['name']);
        $this->assertTrue($call->hasHeader('Authorization', 'Bearer ya29.first'));
        $this->assertDatabaseHas('plugin_actions', ['app_slug' => 'google-drive', 'tool' => 'google_drive_search_files', 'kind' => 'read', 'status' => 'done']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'plugin.google-drive.read']);
    }

    public function test_an_edit_waits_for_approval_and_then_runs_on_the_docs_server_with_the_docs_connection(): void
    {
        $this->fakeGoogle();
        $this->connect('google-drive', 'ya29.drive');
        $this->connect('google-docs', 'ya29.docs');

        $this->callTool('google_docs_update_doc', ['document_id' => 'd1', 'text' => 'Hello'])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertCount(0, $this->toolCalls(), 'nothing runs before approval');
        $action = PluginAction::where('app_slug', 'google-docs')->sole();
        $this->assertSame('pending', $action->status);
        $this->assertStringContainsString('document_id "d1"', $action->summary);

        [, $cookie] = $this->signIn($this->user);
        $this->device($cookie)->postJson("/api/plugin-actions/{$action->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'done')->assertJsonPath('data.action.app', 'Google Docs');

        $call = $this->toolCalls()->sole();
        $this->assertSame('https://docsmcp.googleapis.com/mcp/v1self', $call->url());
        $this->assertSame('update_doc', $call['params']['name']);
        $this->assertTrue($call->hasHeader('Authorization', 'Bearer ya29.docs'));
    }

    public function test_moving_a_file_to_the_trash_waits_for_approval_and_goes_to_the_drive_api_not_the_mcp_server(): void
    {
        Http::fake(['https://www.googleapis.com/drive/v3/files/*' => Http::response(['id' => 'f1', 'name' => 'Old file', 'trashed' => true])]);
        $this->connect('google-drive');

        $this->callTool('google_drive_trash_file', ['fileId' => 'f1'])->assertOk()->assertJsonPath('result.isError', false);

        Http::assertNothingSent();
        $action = PluginAction::where('app_slug', 'google-drive')->sole();
        $this->assertSame('pending', $action->status);

        [, $cookie] = $this->signIn($this->user);
        $this->device($cookie)->postJson("/api/plugin-actions/{$action->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'done');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_starts_with($r->url(), 'https://www.googleapis.com/drive/v3/files/f1?') && $r['trashed'] === true
            && $r->hasHeader('Authorization', 'Bearer ya29.first'));
        Http::assertSentCount(1);
        $this->assertStringContainsString('"trashed":true', $action->fresh()->result_summary);
    }

    public function test_a_file_google_will_not_trash_is_reported_as_a_failed_change_and_a_bad_id_never_leaves_thryve(): void
    {
        Http::fake(['https://www.googleapis.com/drive/v3/files/*' => Http::response(['error' => ['message' => 'File not found: nope.']], 404)]);
        $this->connect('google-drive');
        [, $cookie] = $this->signIn($this->user);

        $this->callTool('google_drive_trash_file', ['fileId' => 'nope'])->assertOk();
        $this->callTool('google_drive_trash_file', ['fileId' => '../about'])->assertOk();
        [$missing, $bad] = PluginAction::where('app_slug', 'google-drive')->orderBy('id')->get()->all();

        $this->device($cookie)->postJson("/api/plugin-actions/{$missing->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'failed')
            ->assertJsonPath('data.action.result_summary', fn ($text) => str_contains($text, 'File not found'));
        $this->device($cookie)->postJson("/api/plugin-actions/{$bad->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'failed');

        Http::assertSentCount(1);
    }

    public function test_a_product_that_this_chat_did_not_switch_on_is_refused(): void
    {
        $this->fakeGoogle();
        $this->connect('google-drive');
        $this->connect('google-sheets', 'ya29.sheets');
        $this->conversation->update(['plugins' => ['google-drive']]);

        $this->callTool('google_sheets_get_values', ['spreadsheet_id' => 's'])->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Google Sheets is not available for this request.');

        $this->assertCount(0, $this->toolCalls());
    }

    // ---- tokens that run out -------------------------------------------------------------------------

    public function test_a_token_about_to_expire_is_renewed_with_the_shared_client_before_the_call(): void
    {
        $this->fakeGoogle();
        $connection = $this->connect(expiresInMinutes: 0);

        $this->callTool('google_drive_search_files')->assertOk()->assertJsonPath('result.isError', false);

        Http::assertSent(fn (Request $r) => $r->url() === self::TOKEN_URL && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === '1//refresh'
            && $r['client_id'] === 'client-id' && $r['client_secret'] === 'client-secret');
        $this->assertTrue($this->toolCalls()->sole()->hasHeader('Authorization', 'Bearer ya29.renewed'));
        $fresh = $connection->fresh();
        $this->assertSame('ya29.renewed', $fresh->access_token);
        $this->assertSame('1//refresh', $fresh->refresh_token, 'the refresh token is kept when Google does not send a new one');
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }

    public function test_when_google_refuses_the_token_it_is_renewed_and_the_call_tried_once_more(): void
    {
        $this->fakeGoogle(mcpAnswer: fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ya29.first') ? Http::response('', 401) : null);
        $connection = $this->connect();

        $this->callTool('google_drive_search_files')->assertOk()->assertJsonPath('result.content.0.text', 'ok:search_files');

        $this->assertSame('ya29.renewed', $connection->fresh()->access_token);
        $this->assertSame('active', $connection->fresh()->status);
    }

    public function test_a_refresh_token_google_no_longer_accepts_means_only_that_product_must_connect_again(): void
    {
        $this->fakeGoogle(tokenAnswer: fn () => Http::response(['error' => 'invalid_grant'], 400));
        $drive = $this->connect(expiresInMinutes: 0);
        $docs = $this->connect('google-docs', 'ya29.docs');

        $this->callTool('google_drive_search_files')->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Google Drive is not available for this request.');

        $this->assertSame('needs_reconnect', $drive->fresh()->status);
        $this->assertSame('active', $docs->fresh()->status);
        $this->assertCount(0, $this->toolCalls());
    }

    public function test_a_connection_without_a_refresh_token_that_google_refuses_needs_reconnecting(): void
    {
        $this->fakeGoogle(mcpAnswer: fn () => Http::response('', 401));
        $connection = $this->connect(refresh: null, expiresInMinutes: null);

        $this->callTool('google_drive_search_files')->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame('needs_reconnect', $connection->fresh()->status);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::TOKEN_URL);
    }

    // ---- the update from the first, single Google plugin ---------------------------------------------

    public function test_the_update_carries_the_old_single_google_plugin_over_to_the_three_products(): void
    {
        foreach (self::LEGACY as $slug) {
            PluginApp::for($slug)->update(['is_enabled' => false, 'agent_ids' => []]); // a page opened before the update already made them, empty
        }
        PluginApp::for('google')->update(['is_enabled' => true, 'agent_ids' => [$this->agent->id], 'name' => 'Google']);
        $other = $this->makeUser(Role::EMPLOYEE);
        $old = PluginConnection::factory()->create(['user_id' => $this->user->id, 'app_slug' => 'google', 'access_token' => 'ya29.old', 'refresh_token' => '1//old', 'token_expires_at' => now()->addMinutes(30), 'external_login' => 'robi@gmail.com']);
        PluginConnection::factory()->create(['user_id' => $other->id, 'app_slug' => 'google', 'access_token' => 'ya29.other', 'refresh_token' => null, 'external_login' => 'other@gmail.com']);
        $rawOld = DB::table('plugin_connections')->where('id', $old->id)->first();

        $migration = require database_path('migrations/2026_11_27_040237_split_google_plugin_into_drive_docs_sheets.php');
        $migration->up();
        $migration->up(); // running it again changes nothing more

        foreach (self::LEGACY as $slug) {
            $app = PluginApp::for($slug);
            $this->assertTrue($app->is_enabled, $slug);
            $this->assertSame([$this->agent->id], $app->agent_ids, $slug);
            $copy = PluginConnection::where('user_id', $this->user->id)->where('app_slug', $slug)->sole();
            $this->assertSame('ya29.old', $copy->access_token);
            $this->assertSame('1//old', $copy->refresh_token);
            $this->assertSame($rawOld->access_token, DB::table('plugin_connections')->where('id', $copy->id)->value('access_token'), 'copied as stored');
            $this->assertSame('robi@gmail.com', $copy->external_login);
        }
        $this->assertSame(0, PluginConnection::where('app_slug', 'google')->count());
        $this->assertSame(6, PluginConnection::count());
        $this->assertNull(PluginConnection::where('user_id', $other->id)->where('app_slug', 'google-docs')->sole()->refresh_token);
        $shared = PluginApp::for('google');
        $this->assertFalse($shared->is_enabled);
        $this->assertSame(['client-id', 'client-secret'], [$shared->oauth_client_id, $shared->oauth_client_secret], 'the shared client is kept');
    }

    // ---- Gmail ---------------------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $tools */
    private function loadGmailTools(array $tools): void
    {
        $snapshots = new \ReflectionProperty(AbstractProvider::class, 'snapshots');
        $current = $snapshots->getValue();
        $current['google-gmail'] = collect($tools)->keyBy('name')->all();
        $snapshots->setValue(null, $current);
    }

    private function gmailTools(): array
    {
        $schema = ['type' => 'object', 'properties' => []];

        return [
            ['name' => 'google_gmail_search_threads', 'remote' => 'search_threads', 'server' => 'gmail', 'description' => 'Search mail', 'kind' => 'read', 'inputSchema' => $schema],
            ['name' => 'google_gmail_create_draft', 'remote' => 'create_draft', 'server' => 'gmail', 'description' => 'Create a draft', 'kind' => 'write', 'inputSchema' => $schema],
            ['name' => 'google_gmail_send_message', 'remote' => 'send_message', 'server' => 'gmail', 'description' => 'Send a draft', 'kind' => 'write', 'inputSchema' => $schema],
        ];
    }

    public function test_gmail_asks_google_only_for_reading_and_composing_mail(): void
    {
        [, $cookie] = $this->signIn($this->user);

        $location = $this->device($cookie)->get('/api/plugins/google-gmail/connect')->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertStringContainsString('/auth/gmail.readonly', $q['scope']);
        $this->assertStringContainsString('/auth/gmail.compose', $q['scope']);
        $this->assertStringNotContainsString('drive', $q['scope']);
        $this->assertStringNotContainsString('/auth/gmail.modify', $q['scope'], 'it cannot change or delete mail');
        $this->assertStringEndsWith('/api/plugins/google/callback', $q['redirect_uri']);
    }

    public function test_gmail_never_offers_or_runs_a_tool_that_sends_mail(): void
    {
        $this->loadGmailTools($this->gmailTools());
        PluginRegistry::forget();
        $this->fakeGoogle();
        $this->connect('google-gmail', 'ya29.mail');

        $names = collect($this->gatewayRpc('tools/list')->json('result.tools'))->pluck('name');
        $this->assertTrue($names->contains('google_gmail_search_threads'));
        $this->assertTrue($names->contains('google_gmail_create_draft'));
        $this->assertFalse($names->contains('google_gmail_send_message'), 'sending is never offered');

        $this->callTool('google_gmail_send_message', ['draftId' => 'd1'])->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Gmail is not available for this request.');
        $this->assertSame(0, PluginAction::where('tool', 'google_gmail_send_message')->count(), 'not even proposed');
        $this->assertCount(0, $this->toolCalls());
    }

    public function test_gmail_reads_run_at_once_and_a_draft_waits_for_approval(): void
    {
        $this->loadGmailTools($this->gmailTools());
        PluginRegistry::forget();
        $this->fakeGoogle();
        $this->connect('google-gmail', 'ya29.mail');

        $this->callTool('google_gmail_search_threads', ['query' => 'invoice'])->assertOk()->assertJsonPath('result.content.0.text', 'ok:search_threads');
        $this->assertSame('https://gmailmcp.googleapis.com/mcp/v1self', $this->toolCalls()->sole()->url());

        $this->callTool('google_gmail_create_draft', ['to' => 'a@example.com', 'subject' => 'Hello', 'body' => 'Hi'])->assertOk();
        $this->assertCount(1, $this->toolCalls(), 'the draft has not been created yet');
        $action = PluginAction::where('tool', 'google_gmail_create_draft')->sole();
        $this->assertSame('pending', $action->status);
        $this->assertStringContainsString('subject "Hello"', $action->summary);

        [, $cookie] = $this->signIn($this->user);
        $this->device($cookie)->postJson("/api/plugin-actions/{$action->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'done')->assertJsonPath('data.action.app', 'Gmail');
        $this->assertSame('create_draft', $this->toolCalls()->last()['params']['name']);
    }

    public function test_the_tool_file_is_written_from_googles_own_list_by_the_command(): void
    {
        $this->fakeGoogle(mcpAnswer: fn (Request $r) => ($r['method'] ?? null) === 'tools/list' ? Http::response(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => [
            ['name' => 'search_threads', 'description' => 'Search', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'gmail.create_draft', 'description' => 'Draft', 'inputSchema' => ['type' => 'object']],
            ['name' => 'send_message', 'description' => 'Send', 'inputSchema' => ['type' => 'object']],
        ]]]) : null);
        $this->connect('google-gmail', 'ya29.mail');
        $path = tempnam(sys_get_temp_dir(), 'tools');

        $this->artisan('thryve:plugin-tools', ['slug' => 'google-gmail', '--path' => $path])->expectsOutputToContain('3 tools saved')->expectsOutputToContain('Never offered')->assertSuccessful();

        $tools = collect(json_decode((string) file_get_contents($path), true)['tools'])->keyBy('name');
        $this->assertSame(['google_gmail_create_draft', 'google_gmail_search_threads', 'google_gmail_send_message'], $tools->keys()->all());
        $this->assertSame(['read', 'write', 'write'], [$tools['google_gmail_search_threads']['kind'], $tools['google_gmail_create_draft']['kind'], $tools['google_gmail_send_message']['kind']]);
        $this->assertSame('gmail.create_draft', $tools['google_gmail_create_draft']['remote']);
        unlink($path);
    }

    public function test_the_command_refuses_without_a_connection_or_an_empty_answer_and_keeps_the_old_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tools');
        file_put_contents($path, 'keep me');

        $this->artisan('thryve:plugin-tools', ['slug' => 'google-gmail', '--path' => $path])->expectsOutputToContain('Nobody is connected')->assertFailed();
        $this->artisan('thryve:plugin-tools', ['slug' => 'github', '--path' => $path])->expectsOutputToContain('does not read its tools')->assertFailed();

        $this->fakeGoogle(mcpAnswer: fn (Request $r) => ($r['method'] ?? null) === 'tools/list' ? Http::response(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => []]]) : null);
        $this->connect('google-gmail', 'ya29.mail');
        $this->artisan('thryve:plugin-tools', ['slug' => 'google-gmail', '--path' => $path])->expectsOutputToContain('returned no tools')->assertFailed();

        $this->assertSame('keep me', file_get_contents($path));
        unlink($path);
    }

    // ---- sending a Gmail draft -----------------------------------------------------------------------

    private const SEND = GoogleGmailProvider::SEND;

    /** @var array<string, mixed> the draft Gmail "has" while a test runs: tests change it to simulate an edit */
    private array $draft = [];

    private function b64(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function draftOf(string $to = 'real@example.com', string $subject = 'Real subject', string $body = 'Real text of the email.', ?array $payload = null): array
    {
        $headers = array_values(array_filter([
            $to !== '' ? ['name' => 'To', 'value' => $to] : null, ['name' => 'Subject', 'value' => $subject],
        ]));

        return ['id' => 'd1', 'message' => ['id' => 'm1', 'payload' => ($payload ?? ['mimeType' => 'text/plain', 'body' => ['data' => $this->b64($body)]]) + ['headers' => $headers]]];
    }

    /** Gmail's draft API: the draft in $this->draft (or 404 when empty), and the send. */
    private function fakeGmailDrafts(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/drafts/send')) {
                return Http::response(['id' => 'sent-1', 'threadId' => 't1', 'labelIds' => ['SENT']]);
            }

            return $this->draft === [] ? Http::response(['error' => ['message' => 'Requested entity was not found.']], 404) : Http::response($this->draft);
        });
    }

    private function allowSending(): void
    {
        PluginApp::for('google-gmail')->update(['is_enabled' => true, 'agent_ids' => [$this->agent->id], 'tools' => [self::SEND => true]]);
        $this->connect('google-gmail', 'ya29.mail');
    }

    private function sends(): Collection
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/drafts/send'))->map(fn ($pair) => $pair[0])->values();
    }

    public function test_sending_is_off_until_an_admin_allows_it_and_other_send_tools_stay_blocked(): void
    {
        $this->loadGmailTools($this->gmailTools());
        PluginRegistry::forget();
        PluginApp::for('google-gmail')->update(['is_enabled' => true, 'agent_ids' => [$this->agent->id]]);
        $this->connect('google-gmail', 'ya29.mail');
        $this->fakeGmailDrafts();
        $this->draft = $this->draftOf();

        $this->assertFalse(collect($this->gatewayRpc('tools/list')->json('result.tools'))->pluck('name')->contains(self::SEND), 'off by default');
        $this->callTool(self::SEND, ['draftId' => 'd1'])->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Gmail is not available for this request.');
        $this->assertSame(0, PluginAction::where('tool', self::SEND)->count());

        [, $cookie] = $this->signIn($this->makeUser(Role::SUPER_ADMIN));
        $r = $this->device($cookie)->patchJson('/api/plugin-apps/google-gmail', ['tools' => [self::SEND => true]])->assertOk();
        $tool = collect(collect($r->json('data.apps'))->firstWhere('slug', 'google-gmail')['tools'])->firstWhere('name', self::SEND);
        $this->assertTrue($tool['enabled']);
        $this->assertTrue($tool['default_off']);
        $names = collect($this->gatewayRpc('tools/list')->json('result.tools'))->pluck('name');
        $this->assertTrue($names->contains(self::SEND));
        $this->assertFalse($names->contains('google_gmail_send_message'), 'any other send tool stays blocked');
        $this->device($cookie)->patchJson('/api/plugin-apps/google-gmail', ['tools' => ['google_gmail_send_message' => true]])->assertStatus(422);
    }

    public function test_the_approve_card_shows_the_real_draft_and_not_what_the_ai_said(): void
    {
        $this->allowSending();
        $this->fakeGmailDrafts();
        $this->draft = $this->draftOf('Real Person <real@example.com>', 'Real subject', 'Real text of the email.');

        $this->callTool(self::SEND, ['draftId' => 'd1', 'to' => 'attacker@evil.test', 'subject' => 'Invented', 'body' => 'Invented', 'bcc' => 'spy@evil.test'])->assertOk()->assertJsonPath('result.isError', false);

        $action = PluginAction::where('tool', self::SEND)->sole();
        $this->assertSame('pending', $action->status);
        $arguments = $action->arguments;
        $this->assertSame('Real Person <real@example.com>', $arguments['to']);
        $this->assertSame('Real subject', $arguments['subject']);
        $this->assertSame('Real text of the email.', $arguments['body_preview']);
        $this->assertArrayNotHasKey('bcc', $arguments);
        $this->assertStringNotContainsString('evil.test', json_encode($arguments));
        $this->assertNotEmpty($arguments['_fingerprint']);
        $this->assertStringContainsString('subject "Real subject"', $action->summary);
        $this->assertCount(0, $this->sends(), 'nothing is sent by proposing');
    }

    public function test_the_text_is_read_once_from_a_mail_with_a_plain_and_an_html_version_and_attachments_are_named(): void
    {
        $this->allowSending();
        $this->fakeGmailDrafts();
        $this->draft = $this->draftOf(payload: ['mimeType' => 'multipart/mixed', 'parts' => [
            ['mimeType' => 'multipart/alternative', 'parts' => [
                ['mimeType' => 'text/plain', 'body' => ['data' => $this->b64('Plain words.')]],
                ['mimeType' => 'text/html', 'body' => ['data' => $this->b64('<p>Plain words.</p>')]],
            ]],
            ['mimeType' => 'application/pdf', 'filename' => 'invoice.pdf', 'body' => ['attachmentId' => 'a1']],
        ]]);

        $this->callTool(self::SEND, ['draftId' => 'd1'])->assertOk();

        $arguments = PluginAction::where('tool', self::SEND)->sole()->arguments;
        $this->assertSame('Plain words.', $arguments['body_preview']);
        $this->assertSame('invoice.pdf', $arguments['attachments']);
    }

    public function test_approving_sends_that_draft_once_as_that_person(): void
    {
        $this->allowSending();
        $this->fakeGmailDrafts();
        $this->draft = $this->draftOf();
        $this->callTool(self::SEND, ['draftId' => 'd1'])->assertOk();
        $action = PluginAction::where('tool', self::SEND)->sole();
        [, $cookie] = $this->signIn($this->user);

        $this->device($cookie)->postJson("/api/plugin-actions/{$action->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'done')->assertJsonPath('data.action.app', 'Gmail');
        $this->device($cookie)->postJson("/api/plugin-actions/{$action->id}/approve")->assertStatus(409);

        $send = $this->sends()->sole();
        $this->assertSame('d1', $send['id']);
        $this->assertTrue($send->hasHeader('Authorization', 'Bearer ya29.mail'));
        $this->assertStringContainsString('"sent":true', $action->fresh()->result_summary);
        $this->assertDatabaseHas('audit_entries', ['action' => 'plugin.google-gmail.approved']);
    }

    public function test_a_draft_changed_after_the_card_was_shown_is_not_sent(): void
    {
        $this->allowSending();
        $this->fakeGmailDrafts();
        $this->draft = $this->draftOf();
        $this->callTool(self::SEND, ['draftId' => 'd1'])->assertOk();
        $action = PluginAction::where('tool', self::SEND)->sole();
        $this->draft = $this->draftOf('someone-else@example.com'); // edited in Gmail after the person looked
        [, $cookie] = $this->signIn($this->user);

        $this->device($cookie)->postJson("/api/plugin-actions/{$action->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'failed')
            ->assertJsonPath('data.action.result_summary', fn ($text) => str_contains($text, 'changed after you were asked'));

        $this->assertCount(0, $this->sends());
    }

    public function test_a_missing_draft_a_draft_without_recipients_or_a_bad_id_cannot_even_be_proposed(): void
    {
        $this->allowSending();
        $this->fakeGmailDrafts();

        $this->callTool(self::SEND, ['draftId' => 'nope'])->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'That draft was not found. Create it first, then try again.');
        $this->draft = $this->draftOf('');
        $this->callTool(self::SEND, ['draftId' => 'd1'])->assertOk()->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'The draft has no recipient, so it cannot be sent.');
        $this->callTool(self::SEND, ['draftId' => '../../settings'])->assertOk()->assertJsonPath('result.isError', true);
        $this->callTool(self::SEND, [])->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame(0, PluginAction::where('tool', self::SEND)->count());
    }

    public function test_each_person_has_a_daily_limit_and_it_is_checked_again_when_approving(): void
    {
        config(['plugins.google.gmail_daily_send_limit' => 1]);
        $this->allowSending();
        $this->fakeGmailDrafts();
        $this->draft = $this->draftOf();
        $this->callTool(self::SEND, ['draftId' => 'd1'])->assertOk()->assertJsonPath('result.isError', false);
        $pending = PluginAction::where('tool', self::SEND)->sole();
        PluginAction::factory()->create(['user_id' => $this->user->id, 'app_slug' => 'google-gmail', 'tool' => self::SEND, 'kind' => 'write', 'status' => 'done', 'executed_at' => now()]);

        $this->callTool(self::SEND, ['draftId' => 'd1', 'again' => 1])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'The daily limit of 1 sent emails has been reached. It resets at midnight.');

        [, $cookie] = $this->signIn($this->user);
        $this->device($cookie)->postJson("/api/plugin-actions/{$pending->id}/approve")->assertOk()->assertJsonPath('data.action.status', 'failed');
        $this->assertCount(0, $this->sends());
    }
}
