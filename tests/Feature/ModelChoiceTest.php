<?php

namespace Tests\Feature;

use App\Ai\StreamTransport;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\AuditEntry;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelChoice;
use App\Models\Role;
use App\Models\UsageRecord;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** A Super Admin approves AI models from the list Hermes reports live; people pick one per conversation. */
class ModelChoiceTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const OPTIONS = 'general.example.com/p/general/api/model/options';

    private const CHAT = 'general.example.com/p/general/v1/chat/completions';

    private Agent $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        config(['services.hermes.url' => null, 'services.hermes.key' => null]);
        Cache::flush();
        $this->general = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true,
            'outbound_key' => 'key-for-general-1234567890', 'outbound_fingerprint' => 'abcd1234']);
    }

    /** @param  list<string>  $models */
    private function hermesOptions(array $models = ['claude-opus-5-5', 'claude-sonnet-5']): array
    {
        return ['model' => 'm', 'provider' => 'anthropic', 'providers' => [
            ['slug' => 'anthropic', 'name' => 'Anthropic', 'authenticated' => true, 'models' => $models],
            ['slug' => 'openai-api', 'name' => 'OpenAI API', 'authenticated' => false, 'models' => ['gpt-9']],
        ]];
    }

    private function completion(): array
    {
        return ['model' => 'echo', 'choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100]];
    }

    private function fakeHermes(?array $options = null): void
    {
        Http::fake([self::OPTIONS => Http::response($options ?? $this->hermesOptions()), self::CHAT => Http::response($this->completion())]);
    }

    private function choice(string $model = 'claude-opus-5-5', array $attrs = []): ModelChoice
    {
        return ModelChoice::factory()->enabled()->create($attrs + ['model' => $model, 'label' => $model]);
    }

    private function lastChatRequestBody(): array
    {
        $body = null;
        Http::assertSent(function ($req) use (&$body) {
            if (str_ends_with($req->url(), '/v1/chat/completions')) {
                $body = $req->data();
            }

            return true;
        });

        return $body;
    }

    // ---- the Super Admin's screen ----------------------------------------------------------------

    public function test_detected_models_are_only_the_signed_in_providers_and_approved_ones_are_marked(): void
    {
        $this->fakeHermes();
        $this->choice('claude-opus-5-5');
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($super)->getJson('/api/model-choices')->assertOk();

        $this->assertTrue($r->json('data.live'));
        $this->assertSame(['claude-opus-5-5', 'claude-sonnet-5'], array_column($r->json('data.detected'), 'model'));
        $this->assertSame([true, false], array_column($r->json('data.detected'), 'approved'));
        $this->assertTrue($r->json('data.choices.0.available'));
        $this->assertStringNotContainsString('gpt-9', $r->getContent());
        $this->assertStringNotContainsString('example.com', $r->getContent());
        $this->assertStringNotContainsString('key-for-general', $r->getContent());
    }

    public function test_when_hermes_cannot_be_read_the_screen_still_opens_and_says_so(): void
    {
        Http::fake([self::OPTIONS => Http::response('', 500)]);
        $this->choice('claude-opus-5-5');

        $r = $this->device($this->cookieFor(Role::SUPER_ADMIN))->getJson('/api/model-choices')->assertOk();

        $this->assertFalse($r->json('data.live'));
        $this->assertSame([], $r->json('data.detected'));
        $this->assertNull($r->json('data.choices.0.available'));
    }

    public function test_a_model_can_only_be_approved_from_the_detected_list_and_starts_switched_off(): void
    {
        $this->fakeHermes();
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($super)->postJson('/api/model-choices', ['provider' => 'anthropic', 'model' => 'claude-typo', 'label' => 'Typo'])->assertStatus(422)->assertJsonPath('error.code', 'model_not_detected');
        $this->device($super)->postJson('/api/model-choices', ['provider' => 'openai-api', 'model' => 'gpt-9', 'label' => 'Not signed in'])->assertStatus(422)->assertJsonPath('error.code', 'model_not_detected');

        $this->device($super)->postJson('/api/model-choices', ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'label' => 'Best', 'hint' => 'For hard work'])
            ->assertStatus(201)->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.label', 'Best');
        $this->device($super)->postJson('/api/model-choices', ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'label' => 'Again'])->assertStatus(422);
        $this->assertSame(1, ModelChoice::count());
        $this->assertSame(1, AuditEntry::where('action', 'model_choice.created')->count());
    }

    public function test_a_model_can_be_turned_on_without_any_price(): void
    {
        $this->fakeHermes();
        $c = ModelChoice::factory()->create();
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($super)->patchJson("/api/model-choices/{$c->id}", ['is_enabled' => true])->assertOk()->assertJsonPath('data.is_enabled', true);
        $this->assertTrue($c->fresh()->is_enabled);
        $this->assertSame(1, AuditEntry::where('action', 'model_choice.updated')->count());
    }

    public function test_only_one_model_is_the_default_and_it_must_be_enabled(): void
    {
        $a = $this->choice('claude-opus-5-5', ['is_default' => true]);
        $b = $this->choice('claude-sonnet-5');
        $off = ModelChoice::factory()->create(['model' => 'claude-haiku']);
        $super = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($super)->patchJson("/api/model-choices/{$b->id}", ['is_default' => true])->assertOk();
        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
        $this->device($super)->patchJson("/api/model-choices/{$off->id}", ['is_default' => true])->assertStatus(422)->assertJsonPath('error.code', 'default_needs_enabled');
        $this->device($super)->patchJson("/api/model-choices/{$b->id}", ['is_enabled' => false])->assertOk()->assertJsonPath('data.is_default', false);
    }

    public function test_admins_can_read_the_list_but_only_a_super_admin_changes_it(): void
    {
        $this->fakeHermes();
        $c = $this->choice();
        $admin = $this->cookieFor(Role::ADMIN);
        $employee = $this->cookieFor(Role::EMPLOYEE);
        $payload = ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'label' => 'Fast'];

        $this->device($admin)->getJson('/api/model-choices')->assertOk();
        $this->device($admin)->postJson('/api/model-choices', $payload)->assertStatus(403);
        $this->device($admin)->patchJson("/api/model-choices/{$c->id}", ['label' => 'x'])->assertStatus(403);
        $this->device($employee)->getJson('/api/model-choices')->assertStatus(403);
        $this->device($employee)->postJson('/api/model-choices', $payload)->assertStatus(403);
        $this->device(null)->getJson('/api/model-choices')->assertStatus(401);
        $this->device(null)->getJson('/api/chat/models?agent_id=1')->assertStatus(401);
        $this->assertSame('claude-opus-5-5', $c->fresh()->label);
    }

    // ---- what people can pick ---------------------------------------------------------------------

    public function test_people_see_only_enabled_models_that_hermes_lists_right_now_and_nothing_about_providers(): void
    {
        $this->fakeHermes($this->hermesOptions(['claude-opus-5-5']));
        $this->choice('claude-opus-5-5', ['hint' => 'For hard work']);
        $this->choice('claude-sonnet-5'); // enabled, but no longer listed
        ModelChoice::factory()->create(['model' => 'claude-haiku']); // listed nowhere, switched off
        $cookie = $this->cookieFor(Role::EMPLOYEE);

        $r = $this->device($cookie)->getJson('/api/chat/models?agent_id='.$this->general->id)->assertOk();

        $this->assertTrue($r->json('data.live'));
        $this->assertSame(['claude-opus-5-5'], array_column($r->json('data.models'), 'model'));
        $this->assertSame(['id', 'label', 'hint', 'model', 'supports_images', 'is_default'], array_keys($r->json('data.models.0')));
        foreach (['anthropic', 'example.com', 'key-for-general'] as $leak) {
            $this->assertStringNotContainsString($leak, $r->getContent());
        }
    }

    public function test_the_list_is_empty_when_hermes_cannot_be_read_and_a_chatbot_they_cannot_use_is_refused(): void
    {
        Http::fake([self::OPTIONS => Http::response('', 503)]);
        $this->choice();
        $other = Agent::create(['name' => 'Coach', 'slug' => 'coach', 'base_url' => 'https://coach.example.com', 'profile' => 'coach', 'outbound_key' => 'key-for-coach-1234567890', 'outbound_fingerprint' => 'efgh5678']);
        $cookie = $this->cookieFor(Role::EMPLOYEE);

        $this->device($cookie)->getJson('/api/chat/models?agent_id='.$this->general->id)->assertOk()->assertJsonPath('data.live', false)->assertJsonPath('data.models', []);
        $this->device($cookie)->getJson('/api/chat/models?agent_id='.$other->id)->assertStatus(403);
    }

    // ---- using a pick -----------------------------------------------------------------------------

    public function test_a_pick_sends_the_exact_model_with_its_provider_and_is_recorded(): void
    {
        $this->fakeHermes();
        $choice = $this->choice();
        $cookie = $this->cookieFor(Role::EMPLOYEE);

        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Plan my week', 'model_choice_id' => $choice->id])->assertOk();

        $body = $this->lastChatRequestBody();
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('anthropic', $body['provider']);
        $this->assertSame($choice->id, Conversation::sole()->model_choice_id);
        $r->assertJsonPath('data.conversation.model_choice_id', $choice->id)->assertJsonPath('data.reply.model', 'claude-opus-5-5')->assertJsonPath('data.reply.model_label', 'claude-opus-5-5');
        $usage = UsageRecord::sole();
        $this->assertSame('claude-opus-5-5', $usage->model);
        $this->assertSame(1100, $usage->input_tokens + $usage->output_tokens);
    }

    public function test_without_a_pick_the_request_is_exactly_what_it_was_before(): void
    {
        $this->fakeHermes();
        $cookie = $this->cookieFor(Role::EMPLOYEE);

        $this->device($cookie)->postJson('/api/chat', ['message' => 'Hi'])->assertOk()->assertJsonPath('data.reply.model', null);

        $body = $this->lastChatRequestBody();
        $this->assertSame('general', $body['model']);
        $this->assertArrayNotHasKey('provider', $body);
        $this->assertSame('general', UsageRecord::sole()->model);
        $this->assertNull(Message::where('role', 'assistant')->sole()->model);
        $this->assertNull(Conversation::sole()->model_choice_id);
    }

    public function test_switching_mid_chat_changes_only_new_answers_and_regenerate_and_edit_keep_the_pick(): void
    {
        $this->fakeHermes();
        $a = $this->choice('claude-opus-5-5');
        $b = $this->choice('claude-sonnet-5');
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        $first = $this->device($cookie)->postJson('/api/chat', ['message' => 'One', 'model_choice_id' => $a->id])->assertOk();
        $id = $first->json('data.conversation.id');
        $userMessageId = $first->json('data.reply.id') - 1;

        $this->device($cookie)->postJson('/api/chat', ['message' => 'Two', 'conversation_id' => $id, 'model_choice_id' => $b->id])->assertOk();
        $this->assertSame('claude-sonnet-5', $this->lastChatRequestBody()['model']);
        $this->assertSame(['claude-opus-5-5', 'claude-sonnet-5'], Message::where('role', 'assistant')->orderBy('id')->pluck('model')->all());

        $this->device($cookie)->postJson("/api/conversations/{$id}/regenerate")->assertOk();
        $this->assertSame('claude-sonnet-5', $this->lastChatRequestBody()['model']);
        $this->device($cookie)->patchJson("/api/conversations/{$id}/messages/{$userMessageId}", ['message' => 'One, edited'])->assertOk();
        $this->assertSame('claude-sonnet-5', $this->lastChatRequestBody()['model']);

        $this->device($cookie)->postJson('/api/chat', ['message' => 'Three', 'conversation_id' => $id, 'model_choice_id' => null])->assertOk();
        $this->assertSame('general', $this->lastChatRequestBody()['model']);
        $this->assertNull(Conversation::sole()->model_choice_id);
    }

    public function test_a_pick_that_is_not_approved_is_refused_and_one_that_was_switched_off_falls_back_to_the_chatbots_own_model(): void
    {
        $this->fakeHermes();
        $off = ModelChoice::factory()->create(['model' => 'claude-haiku']);
        $a = $this->choice('claude-opus-5-5');
        $cookie = $this->cookieFor(Role::EMPLOYEE);

        $this->device($cookie)->postJson('/api/chat', ['message' => 'Hi', 'model_choice_id' => $off->id])->assertStatus(422)->assertJsonPath('error.code', 'model_not_available');
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Hi', 'model_choice_id' => 9999])->assertStatus(422);
        $this->assertSame(0, Conversation::count());

        $id = $this->device($cookie)->postJson('/api/chat', ['message' => 'Hi', 'model_choice_id' => $a->id])->assertOk()->json('data.conversation.id');
        $a->update(['is_enabled' => false]);
        $this->device($cookie)->postJson('/api/chat', ['message' => 'More', 'conversation_id' => $id])->assertOk()->assertJsonPath('data.reply.model', null);
        $this->assertSame('general', $this->lastChatRequestBody()['model']);
    }

    public function test_a_model_that_hermes_no_longer_lists_falls_back_to_the_chatbots_own_model(): void
    {
        $this->fakeHermes($this->hermesOptions(['claude-sonnet-5']));
        $a = $this->choice('claude-opus-5-5');
        $cookie = $this->cookieFor(Role::EMPLOYEE);

        $this->device($cookie)->postJson('/api/chat', ['message' => 'Hi', 'model_choice_id' => $a->id])->assertOk()->assertJsonPath('data.reply.model', null);

        $body = $this->lastChatRequestBody();
        $this->assertSame('general', $body['model']);
        $this->assertArrayNotHasKey('provider', $body);
    }

    public function test_a_model_without_image_support_is_refused_in_a_chat_with_an_image(): void
    {
        Storage::fake('private');
        $this->fakeHermes();
        $text = $this->choice('claude-sonnet-5');
        $vision = $this->choice('claude-opus-5-5', ['supports_images' => true]);
        $employee = $this->makeUser(Role::EMPLOYEE);
        [, $cookie] = $this->signIn($employee);
        $image = function () use ($employee) {
            $key = 't/'.Str::random(12).'.png';
            Storage::disk('private')->put($key, 'png-bytes');

            return Attachment::create(['user_id' => $employee->id, 'original_name' => 'a.png', 'kind' => 'png', 'size' => 9, 'storage_key' => $key, 'sha256' => str_repeat('a', 64), 'extracted_text' => '', 'truncated' => false, 'generated' => false])->id;
        };

        $this->device($cookie)->postJson('/api/chat', ['message' => 'Look', 'model_choice_id' => $text->id, 'attachment_ids' => [$image()]])->assertStatus(422)->assertJsonPath('error.code', 'model_no_images');
        $this->assertSame(0, Conversation::count());

        $id = $this->device($cookie)->postJson('/api/chat', ['message' => 'Look', 'model_choice_id' => $vision->id, 'attachment_ids' => [$image()]])->assertOk()->json('data.conversation.id');
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Again', 'conversation_id' => $id, 'model_choice_id' => $text->id])->assertStatus(422)->assertJsonPath('error.code', 'model_no_images');
    }

    public function test_a_streamed_answer_with_a_pick_sends_the_provider_and_records_the_model(): void
    {
        $this->fakeHermes();
        $choice = $this->choice();
        $payload = null;
        $this->mock(StreamTransport::class, function ($mock) use (&$payload) {
            $mock->shouldReceive('read')->once()->andReturnUsing(function ($url, $headers, $sent, $timeout, $receive) use (&$payload) {
                $payload = $sent;
                $receive('data: '.json_encode(['model' => 'echo', 'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]])."\n\n");
                $receive('data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n");
                $receive('data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5]])."\n\n");
                $receive("data: [DONE]\n\n");

                return false;
            });
        });
        $cookie = $this->cookieFor(Role::EMPLOYEE);

        $this->device($cookie)->postJson('/api/chat', ['message' => 'Hi', 'model_choice_id' => $choice->id], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()])->assertOk()->streamedContent();

        $this->assertSame('claude-opus-5-5', $payload['model']);
        $this->assertSame('anthropic', $payload['provider']);
        $this->assertSame('claude-opus-5-5', Message::where('role', 'assistant')->sole()->model);
        $this->assertSame('claude-opus-5-5', UsageRecord::sole()->model);
    }
}
