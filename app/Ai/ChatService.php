<?php

namespace App\Ai;

use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelChoice;
use App\Models\PluginApp;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginTicket;
use App\Support\BusinessDay;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One chat turn: save what the person wrote, check the allowance, ask the conversation's agent (through the ledger so
 * limits and usage apply), save the answer. Every attempt (send, edit, regenerate, retry) is a separate usage request.
 */
class ChatService
{
    /** Roughly how much earlier conversation is sent along (characters, newest kept). Placeholder until D08. */
    public const CONTEXT_CHARS = 24000;

    public function __construct(private AgentRunner $runner, private HermesClient $hermes, private Allowance $allowance, private AgentService $agents, private ModelCatalog $catalog) {}

    /** @return list<string> labels of sensitive data found, for the warn/block policy */
    public function sensitive(string $text): array
    {
        return SensitiveData::find($text);
    }

    public function start(User $user, ?Agent $agent, string $text): Conversation
    {
        return Conversation::create(['user_id' => $user->id, 'agent_id' => $agent?->id, 'title' => $this->titleFrom($text)]);
    }

    /** @param  iterable<Attachment>  $files  already checked to belong to this person and to be unsent */
    public function addUserMessage(Conversation $c, string $text, iterable $files = [], ?string $outputFormat = null): Message
    {
        $m = Message::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => $text, 'output_format' => $outputFormat]);
        foreach ($files as $f) {
            $f->update(['message_id' => $m->id, 'conversation_id' => $c->id]);
        }
        $c->update(['last_message_at' => now()]);

        return $m;
    }

    /** An edited message keeps the files of the message it replaces. */
    public function carryFiles(Message $from, Message $to): void
    {
        Attachment::where('message_id', $from->id)->update(['message_id' => $to->id]);
    }

    /**
     * Answer the latest user message. Throws TokenLimitReached, AgentUnavailable or ProviderException; the user's
     * message stays saved so the person can retry.
     */
    public function answer(User $user, Conversation $c, string $requestKind = 'send', ?callable $emit = null, ?callable $stop = null, bool $personalize = true, ?callable $status = null): Message
    {
        $agent = $this->agentFor($c);
        $target = null;
        $choice = null;
        if ($agent) {
            $target = $this->agents->targetFor($agent) ?? throw new AgentUnavailable;
            $choice = $this->activeChoice($c, $agent);
            if ($choice) {
                // The person's pick: the exact model and its provider (Hermes ignores a model name that comes without one).
                $target = ['model' => $choice->model, 'provider' => $choice->provider] + $target;
            }
        } elseif ($this->agents->any()) {
            throw new AgentUnavailable; // agents exist but this conversation has none and there is no default to fall back to
        }

        $requestId = 'chat-'.Str::lower(Str::random(16));
        $context = $this->context($c);
        $format = $c->messages()->whereNull('superseded_at')->where('role', 'user')->latest('id')->value('output_format');
        // A format asked for by name (API only) is strict. Otherwise the agent decides, from what the person wrote, whether a file is wanted.
        $system = $format ? GeneratedFiles::instruction($format) : GeneratedFiles::autoInstruction();
        // The person's own preferences come after the company rules. Not for strict file output, and not while a Super Admin views as them.
        if ($personalize && ! $format && ($preferences = Personalization::promptFor($user)) !== '') {
            $system .= "\n\n".$preferences;
        }
        // GitHub, when this person switched it on for this chat: the ticket tells the plugin gateway who is asking.
        if ($personalize && ! $format && ($pluginNote = $this->pluginNote($user, $c, $agent, $requestId)) !== '') {
            $system .= "\n\n".$pluginNote;
        }
        array_unshift($context, ['role' => 'system', 'content' => $system]);
        $estimate = 0;
        foreach ($context as $message) {
            if (is_string($message['content'])) {
                $estimate += (int) ceil(strlen($message['content']) / 4);
            } else {
                foreach ($message['content'] as $part) {
                    $estimate += $part['type'] === 'text' ? (int) ceil(strlen($part['text']) / 4) : 4000;
                }
            }
        }

        $sent = '';
        $raw = '';
        $push = function (string $part) use (&$raw, &$sent, $emit, $format) {
            $raw .= $part;
            if (! $format && $emit) {
                $safe = StreamText::visible($raw);
                if (strlen($safe) > strlen($sent)) {
                    $emit(substr($safe, strlen($sent)));
                    $sent = $safe;
                }
            }
        };
        try {
            $out = $this->runner->run(
                $user, $agent, $requestId, $target['model'] ?? (string) Settings::get('hermes_model_label'),
                fn () => $emit ? $this->hermes->stream($context, $target, 'thryve-user-'.$user->id.'-conversation-'.$c->id.'-'.$requestId, $push, $stop ?? fn () => false, $format ? null : $status) : $this->hermes->chat($context, $target, 'thryve-user-'.$user->id.'-conversation-'.$c->id.'-'.$requestId),
                'chat', $estimate, $c->id, $requestKind,
            );
        } catch (ProviderException $e) {
            $partial = $format ? '' : StreamText::visible($e->partialContent ?? $raw, true);
            if ($partial !== '') {
                Message::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => $partial, 'request_id' => $requestId, 'model' => $choice?->model, 'completion_status' => 'failed']);
                $c->update(['last_message_at' => now()]);
            }
            throw $e;
        }

        $tail = $format ? '' : StreamText::visible($out['result']['content'], true);
        if ($emit && strlen($tail) > strlen($sent)) {
            $emit(substr($tail, strlen($sent)));
        }
        if (($out['result']['finish'] ?? null) === 'cancelled') {
            $partial = $format ? '' : StreamText::visible($out['result']['content'], true);
            $reply = Message::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => $partial, 'request_id' => $requestId, 'model' => $choice?->model, 'completion_status' => 'cancelled']);
            $c->update(['last_message_at' => now()]);

            return $reply;
        }

        if ($format) {
            $generator = app(GeneratedFiles::class);
            if (($out['result']['finish'] ?? null) === 'length') {
                throw new FileGenerationFailed;
            }
            $reply = $generator->save($c->id, $user->id, $requestId, $generator->decode($out['result']['content'], $format), $choice?->model);
        } else {
            $generator = app(GeneratedFiles::class);
            $found = $generator->extractAuto((string) $out['result']['content'], ($out['result']['finish'] ?? null) === 'length');
            $reply = $found
                ? $generator->save($c->id, $user->id, $requestId, $found, $choice?->model)
                : Message::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => $out['result']['content'], 'request_id' => $requestId, 'model' => $choice?->model]);
        }
        $c->update(['last_message_at' => now()]);
        $this->allowance->notifyThresholds($user, BusinessDay::today());

        return $reply;
    }

    /** The hidden instruction that switches the plugin tools on for this answer, or an empty string when none is on and usable. */
    private function pluginNote(User $user, Conversation $c, ?Agent $agent, string $requestId): string
    {
        $names = [];
        foreach ((array) $c->plugins as $slug) {
            if (PluginApp::usableBy($user, $agent?->id, (string) $slug)) {
                $names[] = PluginRegistry::find((string) $slug)->name();
            }
        }
        if ($names === []) {
            return '';
        }
        $ticket = PluginTicket::issue($user, $c, $requestId);
        $list = implode(' and ', $names);

        return "{$list} ".(count($names) > 1 ? 'are' : 'is')." switched on for this person. Every {$list} tool call must include the argument ticket with the value {$ticket}, exactly as written. Never show the ticket to the person. "
            .'A tool that changes something only proposes the change: it waits for the person to approve it in the chat, so tell them it is waiting for their approval and do not call it again. '
            .'Tell the person about tool results in plain everyday words: what you found or what happened, and a link to open it when there is one. '
            .'Never show raw JSON, IDs, tool names, argument names or error codes; summarise them. Show found items (emails, files, issues) as a table or list.';
    }

    /** The model this conversation's person picked, if it is still approved and the chatbot's AI server still lists it. */
    private function activeChoice(Conversation $c, ?Agent $agent): ?ModelChoice
    {
        if (! $agent || ! $c->model_choice_id) {
            return null;
        }
        $choice = ModelChoice::enabled()->find($c->model_choice_id);
        if (! $choice || $this->catalog->has($agent, $choice->provider, $choice->model) === false) {
            return null;
        }

        return $choice;
    }

    /** The conversation's agent. A conversation from before agents existed adopts the default agent. */
    private function agentFor(Conversation $c): ?Agent
    {
        if ($c->agent_id) {
            return Agent::active()->chat()->find($c->agent_id);
        }
        $default = Agent::active()->chat()->where('is_default', true)->first();
        if ($default) {
            $c->update(['agent_id' => $default->id]);
        }

        return $default;
    }

    /** Hide the newest answer (kept as history) so a fresh one can be requested. */
    public function supersedeLastAnswer(Conversation $c): void
    {
        $last = $c->messages()->whereNull('superseded_at')->orderByDesc('id')->first();
        if ($last && $last->role === 'assistant') {
            $last->update(['superseded_at' => now()]);
        }
    }

    /** Replace a user message and everything after it. History is kept, hidden. */
    public function supersedeFrom(Conversation $c, Message $from): void
    {
        $c->messages()->whereNull('superseded_at')->where('id', '>=', $from->id)->update(['superseded_at' => now()]);
    }

    /** @return list<array{role: string, content: string}> newest messages that fit, oldest first */
    private function context(Conversation $c): array
    {
        $rows = $c->messages()->whereNull('superseded_at')->where(fn ($q) => $q->where('role', '!=', 'assistant')->orWhere('content', '!=', ''))->orderByDesc('id')->get(['id', 'role', 'content']);
        $files = Attachment::where('user_id', $c->user_id)->where('conversation_id', $c->id)->whereIn('message_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('message_id');
        $kept = [];
        $size = 0;
        $imageBytes = 0;
        foreach ($rows as $r) {
            $content = $r->content;
            $images = [];
            foreach ($files[$r->id] ?? [] as $f) {
                if ($f->generated) {
                    $content .= "\n[Previously generated file: {$f->original_name}]";

                    continue;
                }
                if (in_array($f->kind, ['png', 'jpg', 'jpeg'], true)) {
                    $bytes = Storage::disk('private')->get($f->storage_key);
                    if ($imageBytes + strlen($bytes) > 6 * 1024 * 1024) {
                        $content .= "\n[Earlier image omitted to fit the request limit: {$f->original_name}. Ask the user to attach it again if needed.]";

                        continue;
                    }
                    $imageBytes += strlen($bytes);
                    $mime = $f->kind === 'png' ? 'image/png' : 'image/jpeg';
                    $images[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes)]];

                    continue;
                }
                $content .= "\n\n[Attached file: {$f->original_name}".($f->truncated ? ' (shortened)' : '')."]\n{$f->extracted_text}\n[End of file]";
            }
            $size += strlen($content);
            if ($kept && $size > self::CONTEXT_CHARS) {
                break;
            }
            $kept[] = ['role' => $r->role, 'content' => $images ? array_merge([['type' => 'text', 'text' => $content]], $images) : $content];
        }

        return array_reverse($kept);
    }

    private function titleFrom(string $text): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text));

        return Str::limit($t === '' ? 'New conversation' : $t, 60, '…');
    }
}
