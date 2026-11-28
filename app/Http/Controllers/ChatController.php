<?php

namespace App\Http\Controllers;

use App\Ai\AgentUnavailable;
use App\Ai\ChatService;
use App\Ai\FileGenerationFailed;
use App\Ai\GeneratedFiles;
use App\Ai\ModelCatalog;
use App\Ai\ProviderException;
use App\Ai\TokenLimitReached;
use App\Http\Middleware\PrepareChatRequest;
use App\Http\Responses\Api;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelChoice;
use App\Models\PluginAction;
use App\Models\PluginApp;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Support\AgentAvatar;
use App\Support\Impersonation;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private conversations: every query is scoped to the signed-in person, and another person's id looks like "not found". */
class ChatController extends Controller
{
    /** @var array<string, string>|null approved model name => label, read once per request */
    private ?array $modelLabels = null;

    public function __construct(private ChatService $chat, private ModelCatalog $models) {}

    private function mine(Request $request, int|string $id): Conversation
    {
        return Conversation::visible()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    /** The chatbots this person may start a chat with. Never any address, key or profile name. */
    public function agents(Request $request): JsonResponse
    {
        return Api::ok(Agent::availableTo($request->user())->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'description' => $a->description, 'is_default' => $a->is_default, 'avatar' => AgentAvatar::url($a->id)])->values());
    }

    /** The approved models this person may pick for a chatbot: enabled ones that the chatbot's AI server lists right now. */
    public function models(Request $request): JsonResponse
    {
        $d = $request->validate(['agent_id' => ['required', 'integer']]);
        $agent = Agent::availableTo($request->user())->find($d['agent_id']);
        if (! $agent) {
            return Api::error('agent_not_available', 'You do not have access to that chatbot.', 403);
        }
        $catalog = $this->models->for($agent);
        if (! $catalog['live']) {
            return Api::ok(['live' => false, 'models' => []]);
        }
        $listed = collect($catalog['models'])->map(fn ($m) => $m['provider'].'|'.$m['model'])->flip();

        return Api::ok(['live' => true, 'models' => ModelChoice::enabled()->orderByDesc('is_default')->orderBy('label')->get()
            ->filter(fn ($c) => $listed->has($c->provider.'|'.$c->model))
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label, 'hint' => $c->hint, 'model' => $c->model, 'supports_images' => $c->supports_images, 'is_default' => $c->is_default])->values()]);
    }

    /** The outside apps this person has connected and may use with this chatbot. */
    public function plugins(Request $request): JsonResponse
    {
        $d = $request->validate(['agent_id' => ['required', 'integer']]);
        $agent = Agent::availableTo($request->user())->find($d['agent_id']);
        if (! $agent) {
            return Api::error('agent_not_available', 'You do not have access to that chatbot.', 403);
        }

        return Api::ok(['plugins' => array_map(fn (string $slug) => ['slug' => $slug, 'name' => PluginRegistry::find($slug)->name()], PluginApp::slugsUsableBy($request->user(), $agent->id))]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'archived' => ['nullable', 'boolean']]);
        $q = Conversation::visible()->where('user_id', $request->user()->id);
        ($f['archived'] ?? false) ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        if (filled($f['q'] ?? null)) {
            $like = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)
                ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('messages')->whereColumn('messages.conversation_id', 'conversations.id')->whereNull('superseded_at')->where('content', 'like', $like)));
        }

        return Api::ok($q->with('agent')->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get()->map(fn ($c) => $this->summary($c)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $c = $this->mine($request, $id);
        PluginAction::expireStale();
        $pending = PluginAction::where('conversation_id', $c->id)->where('user_id', $request->user()->id)->where('status', 'pending')->orderBy('id')->get()->map(fn ($a) => $a->present(true))->all();

        return Api::ok($this->summary($c) + ['pending_actions' => $pending, 'messages' => $c->messages()->whereNull('superseded_at')->orderBy('id')->get()->map(fn ($m) => $this->message($m))]);
    }

    /** Start a conversation (or continue one) with a new message. */
    public function send(Request $request): JsonResponse|StreamedResponse
    {
        $d = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'integer'],
            'agent_id' => ['nullable', 'integer'],
            'acknowledge_sensitive' => ['nullable', 'boolean'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer'],
            'output_format' => ['nullable', 'in:'.implode(',', GeneratedFiles::FORMATS)],
            'model_choice_id' => ['nullable', 'integer'],
            'plugins' => ['nullable', 'array'],
            'plugins.*' => ['string'],
        ]);
        $text = trim($d['message']);
        if ($text === '') {
            return Api::error('validation_failed', 'Type a message first.', 422, ['message' => ['Type a message first.']]);
        }
        // A pick is optional. Sending the key with null goes back to the chatbot's own model; leaving it out keeps the conversation's pick.
        $pickGiven = array_key_exists('model_choice_id', $d);
        $pluginsGiven = array_key_exists('plugins', $d); // like the model: present sets the plugins (empty clears them), absent keeps what the chat has
        $choice = $pickGiven && $d['model_choice_id'] !== null ? ModelChoice::enabled()->find($d['model_choice_id']) : null;
        if ($pickGiven && $d['model_choice_id'] !== null && ! $choice) {
            return Api::error('model_not_available', 'That model is not available. Choose another one.', 422, ['model_choice_id' => ['That model is not available.']]);
        }
        $files = $this->files($request, $d['attachment_ids'] ?? []);
        if ($files instanceof JsonResponse) {
            return $files;
        }
        if ($blocked = $this->sensitiveResponse($text.' '.$files->pluck('extracted_text')->implode(' '), (bool) ($d['acknowledge_sensitive'] ?? false))) {
            return $blocked;
        }

        $user = $request->user();
        if (isset($d['conversation_id'])) {
            $c = $this->mine($request, $d['conversation_id']);
            if ($denied = $this->accessProblem($user, $c)) {
                return $denied;
            }
            $effective = $pickGiven ? $choice : ($c->model_choice_id ? ModelChoice::enabled()->find($c->model_choice_id) : null);
            if ($problem = $this->imageProblem($effective, $files, $c)) {
                return $problem;
            }
            if ($pluginsGiven && ($problem = $this->pluginProblem($user, Agent::active()->find($c->agent_id), $d['plugins'] ?? []))) {
                return $problem;
            }
        } else {
            $agent = $this->pickAgent($user, $d['agent_id'] ?? null);
            if ($agent instanceof JsonResponse) {
                return $agent;
            }
            if ($problem = $this->imageProblem($choice, $files, null)) {
                return $problem;
            }
            if ($pluginsGiven && ($problem = $this->pluginProblem($user, $agent, $d['plugins'] ?? []))) {
                return $problem;
            }
            $c = $this->chat->start($user, $agent, $text);
        }
        if ($pluginsGiven) {
            $c->update(['plugins' => array_values(array_unique($d['plugins'] ?? [])) ?: null]);
        }
        if ($pickGiven) {
            $c->update(['model_choice_id' => $choice?->id]);
        }
        $this->chat->addUserMessage($c, $text, $files, $d['output_format'] ?? null);

        return $this->respond($request, $c);
    }

    /** Ask again for the last user message: after a failure, or to replace the current answer. */
    public function regenerate(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $c = $this->mine($request, $id);
        if ($denied = $this->accessProblem($request->user(), $c)) {
            return $denied;
        }
        $this->chat->supersedeLastAnswer($c);
        if (! $c->messages()->whereNull('superseded_at')->exists()) {
            return Api::error('nothing_to_answer', 'This conversation has no message to answer.', 409);
        }

        return $this->respond($request, $c, 'regenerate');
    }

    /** Edit a user message. It and everything after it are replaced (kept as hidden history) and a new answer is requested. */
    public function edit(Request $request, int $id, int $messageId): JsonResponse|StreamedResponse
    {
        $c = $this->mine($request, $id);
        if ($denied = $this->accessProblem($request->user(), $c)) {
            return $denied;
        }
        $d = $request->validate(['message' => ['required', 'string', 'max:20000'], 'acknowledge_sensitive' => ['nullable', 'boolean']]);
        $original = $c->messages()->whereNull('superseded_at')->where('role', 'user')->findOrFail($messageId);
        $text = trim($d['message']);
        if ($text === '') {
            return Api::error('validation_failed', 'Type a message first.', 422, ['message' => ['Type a message first.']]);
        }
        if ($blocked = $this->sensitiveResponse($text, (bool) ($d['acknowledge_sensitive'] ?? false))) {
            return $blocked;
        }
        $this->chat->supersedeFrom($c, $original);
        $new = $this->chat->addUserMessage($c, $text, [], $original->output_format);
        $this->chat->carryFiles($original, $new);

        return $this->respond($request, $c, 'edit');
    }

    public function rate(Request $request, int $id, int $messageId): JsonResponse
    {
        $c = $this->mine($request, $id);
        $d = $request->validate(['rating' => ['nullable', 'in:up,down']]);
        $m = $c->messages()->whereNull('superseded_at')->where('role', 'assistant')->findOrFail($messageId);
        $m->update(['rating' => $d['rating'] ?? null]);

        return Api::ok($this->message($m));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = $this->mine($request, $id);
        $d = $request->validate(['title' => ['sometimes', 'string', 'min:1', 'max:150'], 'archived' => ['sometimes', 'boolean']]);
        if (isset($d['title'])) {
            $c->title = trim($d['title']);
        }
        if (array_key_exists('archived', $d)) {
            $c->archived_at = $d['archived'] ? now() : null;
        }
        $c->save();

        return Api::ok($this->summary($c));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->mine($request, $id)->update(['deleted_at' => now()]);

        return Api::ok(null);
    }

    /** Which chatbot a new conversation uses. Null means "no agents are set up at all" (the old single-server setup). */
    private function pickAgent(User $user, ?int $agentId): Agent|JsonResponse|null
    {
        if (! Agent::active()->chat()->exists()) {
            return null;
        }
        $available = Agent::availableTo($user)->orderByDesc('is_default')->get();
        if ($available->isEmpty()) {
            return Api::error('no_chatbot', 'No chatbot is available to you yet. Ask an administrator.', 403);
        }
        if ($agentId === null) {
            return $available->firstWhere('is_default', true) ?? ($available->count() === 1 ? $available->first()
                : Api::error('agent_required', 'Choose which chatbot to use.', 422, ['agent_id' => ['Choose which chatbot to use.']]));
        }

        return $available->firstWhere('id', $agentId)
            ?? Api::error('agent_not_available', 'You do not have access to that chatbot.', 403);
    }

    /** An existing conversation can only be continued with its own chatbot, and only while the person may use it. */
    private function accessProblem(User $user, Conversation $c): ?JsonResponse
    {
        if ($c->agent_id === null) {
            return null; // from before agents existed: it adopts the default agent on the next answer
        }
        $agent = Agent::active()->find($c->agent_id);
        if ($agent && Agent::canUse($user, $agent)) {
            return null;
        }

        return Api::error('agent_not_available', 'This chatbot is not available to you right now. You can still read this conversation.', 403);
    }

    /** Every plugin asked for must be one this person can use with this chatbot right now. */
    private function pluginProblem(User $user, ?Agent $agent, array $slugs): ?JsonResponse
    {
        foreach ($slugs as $slug) {
            if ($agent === null || ! PluginApp::usableBy($user, $agent->id, (string) $slug)) {
                return Api::error('plugin_not_available', 'That plugin is not available. Connect it on the Plugins page first, or ask an administrator.', 422, ['plugins' => ['That plugin is not available.']]);
            }
        }

        return null;
    }

    /** A model that cannot read images cannot answer in a conversation that has one. */
    private function imageProblem(?ModelChoice $choice, Collection $files, ?Conversation $c): ?JsonResponse
    {
        if (! $choice || $choice->supports_images) {
            return null;
        }
        $kinds = ['png', 'jpg', 'jpeg'];
        $hasImage = $files->whereIn('kind', $kinds)->isNotEmpty() || ($c && Attachment::where('conversation_id', $c->id)->whereIn('kind', $kinds)->exists());

        return $hasImage ? Api::error('model_no_images', "{$choice->label} cannot read images. Choose another model or remove the image.", 422) : null;
    }

    /** Files named in a send must be this person's own, unsent, and within the per-message limit. */
    private function files(Request $request, array $ids): Collection|JsonResponse
    {
        $ids = array_values(array_unique($ids));
        $max = (int) Settings::get('file_max_count');
        if (count($ids) > $max) {
            return Api::error('too_many_files', "You can attach up to {$max} files to a message.", 422);
        }
        $files = Attachment::where('user_id', $request->user()->id)->whereNull('message_id')->whereIn('id', $ids)->get();
        if ($files->count() !== count($ids)) {
            return Api::error('file_not_found', 'One of the attached files is no longer available. Remove it and attach it again.', 422);
        }
        if ($files->whereIn('kind', ['png', 'jpg', 'jpeg'])->sum('size') > 6 * 1024 * 1024) {
            return Api::error('images_too_large', 'Attach no more than 6 MB of images per message. Use smaller images.', 422);
        }

        return $files;
    }

    private function sensitiveResponse(string $text, bool $acknowledged): ?JsonResponse
    {
        $found = $this->chat->sensitive($text);
        if (! $found) {
            return null;
        }
        $what = implode(', ', $found);
        if (Settings::get('sensitive_data_mode') === 'block') {
            return Api::error('sensitive_blocked', "This message looks like it contains {$what}. Remove it and try again.", 422, ['found' => $found]);
        }
        if (! $acknowledged) {
            return Api::error('sensitive_warning', "This message looks like it contains {$what}. Send it anyway only if it is safe to share.", 409, ['found' => $found]);
        }

        return null;
    }

    public function stop(Request $request, string $stream): JsonResponse
    {
        $row = DB::table('chat_requests')->where('id', $stream)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        if ($row->status === 'running') {
            DB::table('chat_requests')->where('id', $stream)->where('status', 'running')->update(['cancel_requested' => true, 'updated_at' => now()]);
        }

        return Api::ok(['requested' => $row->status === 'running', 'status' => $row->status]);
    }

    private function streamResponse(Request $request, Conversation $c, string $kind): StreamedResponse
    {
        $id = $request->attributes->get('chat_request_id');
        $sessionId = $request->session()->getId();
        $userId = $request->user()->id;
        $impersonatorId = Impersonation::impersonatorId($request); // when set, this browser holds the Super Admin's session, not the employee's
        $request->attributes->set('viewing_as', $impersonatorId !== null); // read later inside the stream, where the session may no longer be open

        return response()->stream(function () use ($request, $c, $kind, $id, $sessionId, $userId, $impersonatorId) {
            $old = ignore_user_abort(true);
            // A long answer can stream for minutes. PHP's own script time limit (often 30 to 300 seconds on shared hosting) must
            // not cut it: no limit, or the configured cap plus a minute. Some hosts forbid this call, which is harmless.
            $cap = (int) config('services.hermes.stream_max_seconds');
            @set_time_limit($cap > 0 ? $cap + 60 : 0);
            $status = 'failed';
            $accessLost = false;
            $event = function (string $event, array $data) use (&$accessLost) {
                if ($accessLost && $event !== 'failure') {
                    return;
                }echo 'event: '.$event."\n".'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                if (! app()->runningUnitTests()) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }flush();
                }
            };
            $checked = 0.;
            $cancelled = false;
            $pulse = microtime(true);
            $leased = 0.;
            $stop = function () use ($id, $sessionId, $userId, $impersonatorId, $c, &$checked, &$cancelled, &$accessLost, &$pulse, &$leased) {
                if (! app()->runningUnitTests() && microtime(true) - $checked < 0.5) {
                    return $cancelled;
                }$checked = microtime(true);
                $row = DB::transaction(function () use ($id, $userId, &$leased) {
                    DB::table('users')->where('id', $userId)->lockForUpdate()->first();
                    $row = DB::table('chat_requests')->where('id', $id)->first();
                    if ($row && $row->status === 'running' && ! $row->cancel_requested && now()->lt($row->expires_at) && microtime(true) - $leased >= 10) {
                        $row->expires_at = now()->addSeconds(PrepareChatRequest::LEASE_SECONDS);
                        DB::table('chat_requests')->where('id', $id)->update(['expires_at' => $row->expires_at]);
                        $leased = microtime(true);
                    }

                    return $row;
                });
                $u = User::find($userId);
                $agent = $c->agent_id ? Agent::active()->find($c->agent_id) : null;
                $owner = $impersonatorId ? User::find($impersonatorId) : $u; // whose single session this browser holds
                $accessLost = (bool) (! $u?->is_active || ! $owner?->is_active || $owner->session_id !== $sessionId || ($c->agent_id && (! $agent || ! Agent::canUse($u, $agent))));
                if (! app()->runningUnitTests() && ! $accessLost && microtime(true) - $pulse >= 1) {
                    echo ": keep-alive\n\n";
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                    $pulse = microtime(true);
                }

                return $cancelled = (bool) (connection_aborted() || ! $row || $row->status !== 'running' || now()->gte($row->expires_at) || $row->cancel_requested || $accessLost);
            };
            try {
                $event('started', ['stream_id' => $id, 'conversation' => $this->summary($c)]);
                $response = $this->respond($request, $c, $kind, fn ($text) => $event('delta', ['text' => $text]), $stop, fn ($text) => $event('status', ['text' => $text]));
                $checked = 0.;
                $stop();
                $payload = $response->getData(true);
                if ($accessLost) {
                    $status = 'cancelled';
                    $event('failure', ['code' => 'auth_changed', 'message' => 'Your access changed. Please sign in again.']);
                } elseif (isset($payload['error'])) {
                    $event('failure', $payload['error']);
                } else {
                    $status = ($payload['data']['reply']['completion_status'] ?? null) === 'cancelled' ? 'cancelled' : 'completed';
                    $event('complete', $payload['data']);
                }
            } catch (\Throwable $e) {
                report($e);
                $event('failure', ['code' => 'ai_failed', 'message' => 'The response was interrupted. Reopen this conversation to check its saved state.', 'details' => ['conversation' => $this->summary($c)]]);
            } finally {
                DB::table('chat_requests')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
                ignore_user_abort($old);
            }
        }, 200, ['Content-Type' => 'text/event-stream; charset=UTF-8', 'Cache-Control' => 'private, no-cache, no-store, no-transform', 'X-Accel-Buffering' => 'no', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function respond(Request $request, Conversation $c, string $requestKind = 'send', ?callable $emit = null, ?callable $stop = null, ?callable $status = null): JsonResponse|StreamedResponse
    {
        // A new chat only gets its conversation now: tie the reservation to it so the same chat cannot answer twice.
        if ($id = $request->attributes->get('chat_request_id')) {
            DB::table('chat_requests')->where('id', $id)->whereNull('conversation_id')->update(['conversation_id' => $c->id, 'expires_at' => DB::raw('expires_at')]); // naming expires_at stops MariaDB from resetting this lease to now
        }
        if ($emit === null && str_contains($request->header('Accept', ''), 'text/event-stream')) {
            return $this->streamResponse($request, $c, $requestKind);
        }
        $base = ['conversation' => $this->summary($c)];
        try {
            $viewingAs = (bool) $request->attributes->get('viewing_as', Impersonation::active($request));
            $reply = $this->chat->answer($request->user(), $c, $requestKind, $emit, $stop, ! $viewingAs, $status);
        } catch (FileGenerationFailed $e) {
            return Api::error('file_generation_failed', $e->getMessage().' Your message is saved; generation may have used tokens.', 502, $base);
        } catch (TokenLimitReached $e) {
            return Api::error('token_limit', $e->getMessage(), 429, $base);
        } catch (AgentUnavailable) {
            return Api::error('ai_unavailable', 'This chatbot is not available right now. Your message is saved: try again in a moment.', 503, $base);
        } catch (ProviderException $e) {
            $msg = match ($e->category) {
                'timeout' => 'The assistant is taking too long. It may still finish: check this conversation again in a minute before sending the message twice.',
                'bad_request' => 'The assistant could not handle this message. Try rephrasing it.',
                'image_budget' => 'Images in this conversation exceed the 6 MB request limit. Use smaller images in a new conversation.',
                default => 'The assistant could not answer. Your message is saved: try again.',
            };

            return Api::error('ai_failed', $msg, 502, $base);
        }

        return Api::ok($base + ['reply' => $this->message($reply)]);
    }

    private function summary(Conversation $c): array
    {
        return ['id' => $c->id, 'title' => $c->title, 'archived' => $c->archived_at !== null, 'last_message_at' => $c->last_message_at?->toIso8601String(), 'model_choice_id' => $c->model_choice_id, 'plugins' => $c->plugins ?? [],
            'agent' => $c->agent ? ['id' => $c->agent->id, 'name' => $c->agent->name, 'avatar' => AgentAvatar::url($c->agent->id)] : null];
    }

    private function message(Message $m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'rating' => $m->rating, 'completion_status' => $m->completion_status, 'created_at' => $m->created_at->toIso8601String(),
            'model' => $m->model, 'model_label' => $m->model ? ($this->modelLabels ??= ModelChoice::pluck('label', 'model')->all())[$m->model] ?? $m->model : null,
            'attachments' => Attachment::where('message_id', $m->id)->orderBy('id')->get()->map(fn ($a) => AttachmentController::present($a))->all()];
    }
}
