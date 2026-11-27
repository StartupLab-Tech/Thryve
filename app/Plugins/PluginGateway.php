<?php

namespace App\Plugins;

use App\Models\Conversation;
use App\Models\PluginAction;
use App\Models\PluginApp;
use App\Models\PluginConnection;
use App\Models\User;
use App\Support\Audit;

/**
 * Thryve's side of the plugin: an MCP server that Hermes calls (JSON-RPC over HTTP). Hermes cannot say which person is asking,
 * so every tool call carries the ticket Thryve put in the hidden instructions. Reads go straight to the app as that person.
 * Anything that changes data is only recorded as a pending action here: it runs after the person approves it in the chat.
 * Every refusal gives the same short answer, so nothing about the cause leaks.
 */
final class PluginGateway
{
    private const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const UNKNOWN_TOOL = 'That tool is not available for this request.';

    /** @param  array<string, mixed>  $rpc  @return array<string, mixed>|null null for a notification (no reply body) */
    public function handle(array $rpc): ?array
    {
        $id = $rpc['id'] ?? null;
        $method = (string) ($rpc['method'] ?? '');
        if ($id === null && str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->reply($id, [
                'protocolVersion' => in_array($rpc['params']['protocolVersion'] ?? '', self::VERSIONS, true) ? $rpc['params']['protocolVersion'] : self::VERSIONS[1],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'thryve-plugins', 'version' => '1'],
            ]),
            'ping' => $this->reply($id, (object) []),
            'tools/list' => $this->reply($id, ['tools' => $this->toolList()]),
            'tools/call' => $this->reply($id, $this->call((array) ($rpc['params'] ?? []))),
            default => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']],
        };
    }

    /** @return list<array<string, mixed>> */
    private function toolList(): array
    {
        $list = [];
        foreach (PluginRegistry::all() as $slug => $provider) {
            $app = PluginApp::for($slug);
            if (! $app->is_enabled) {
                continue;
            }
            foreach ((new ToolSet($provider))->enabledFor($app) as $tool) {
                $schema = $tool['inputSchema'];
                $schema['properties'] = (array) ($schema['properties'] ?? []) + ['ticket' => ['type' => 'string', 'description' => 'The ticket from your instructions. Always include it exactly as given.']];
                $schema['required'] = array_values(array_unique([...(array) ($schema['required'] ?? []), 'ticket']));
                $list[] = ['name' => $tool['name'], 'description' => $tool['description'], 'inputSchema' => $schema, 'annotations' => ['readOnlyHint' => $tool['kind'] === 'read']];
            }
        }

        return $list;
    }

    /** @param  array<string, mixed>  $params  @return array<string, mixed> */
    private function call(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = (array) ($params['arguments'] ?? []);
        $ticket = (string) ($arguments['ticket'] ?? '');
        unset($arguments['ticket']);

        $provider = PluginRegistry::ownerOfTool($name);
        if ($provider === null) {
            return $this->text(self::UNKNOWN_TOOL, true);
        }
        $context = $this->context($ticket, $provider, $name);
        if ($context === null) {
            return $this->refused($provider);
        }
        [$user, $conversation, $connection, $tool, $requestId] = $context;

        return $tool['kind'] === 'read'
            ? $this->read($provider, $user, $conversation, $connection, $name, $arguments, $requestId)
            : $this->propose($provider, $user, $conversation, $connection, $name, $arguments, $requestId);
    }

    /** @return array{0: User, 1: Conversation, 2: PluginConnection, 3: array<string, mixed>, 4: string}|null */
    private function context(string $ticket, Provider $provider, string $name): ?array
    {
        $found = PluginTicket::resolve($ticket);
        $user = $found ? User::find($found['user_id']) : null;
        if (! $user || ! $user->is_active) {
            return null;
        }
        $conversation = Conversation::visible()->where('user_id', $user->id)->find($found['conversation_id']);
        $app = PluginApp::for($provider->slug());
        $tools = new ToolSet($provider);
        $tool = $tools->find($name);
        $connection = PluginConnection::active()->where('user_id', $user->id)->where('app_slug', $provider->slug())->first();
        if (! $conversation || ! in_array($provider->slug(), (array) $conversation->plugins, true) || ! $app->is_enabled || ! $app->allowsAgent($conversation->agent_id)
            || ! $connection || ! $tool || ! $tools->enabled($app, $name)) {
            return null;
        }

        return [$user, $conversation, $connection, $tool, $found['request_id']];
    }

    /** @param  array<string, mixed>  $arguments  @return array<string, mixed> */
    private function read(Provider $provider, User $user, Conversation $conversation, PluginConnection $connection, string $name, array $arguments, string $requestId): array
    {
        $summary = ActionSummary::for($name, $arguments);
        try {
            $result = $provider->callTool($connection, $name, $arguments, true);
        } catch (PluginAuthFailed) {
            $connection->update(['status' => 'needs_reconnect']);
            $this->record($provider, $user, $conversation, $requestId, $name, 'read', 'failed', $summary, $provider->name().' refused the login');

            return $this->refused($provider);
        } catch (PluginCallFailed $e) {
            $this->record($provider, $user, $conversation, $requestId, $name, 'read', 'failed', $summary, $e->getMessage());

            return $this->text($provider->name().' did not answer. Try again later.', true);
        }
        $connection->update(['last_used_at' => now()]);
        $this->record($provider, $user, $conversation, $requestId, $name, 'read', $result['is_error'] ? 'failed' : 'done', $summary,
            $result['is_error'] ? $provider->name().' reported an error: '.mb_substr(trim((string) preg_replace('/\s+/', ' ', $result['text'])), 0, 150) : null);
        $cap = (int) config('plugins.result_cap');
        $text = $result['text'];
        if (mb_strlen($text) > $cap) {
            $text = mb_substr($text, 0, $cap)."\n[Shortened: the result was longer than {$cap} characters.]";
        }

        return $this->text($text, $result['is_error']);
    }

    /** @param  array<string, mixed>  $arguments  @return array<string, mixed> */
    private function propose(Provider $provider, User $user, Conversation $conversation, PluginConnection $connection, string $name, array $arguments, string $requestId): array
    {
        // A plugin that must show the real thing (Gmail's send) looks it up itself; what the AI said about it is replaced.
        if ($provider instanceof PreviewsChanges) {
            try {
                $arguments = $provider->previewChange($connection, $name, $arguments);
            } catch (PluginAuthFailed) {
                $connection->update(['status' => 'needs_reconnect']);

                return $this->refused($provider);
            } catch (PluginCallFailed $e) {
                return $this->text($e->getMessage(), true);
            }
        }
        ksort($arguments);
        $hash = hash('sha256', $name.json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $existing = PluginAction::where('conversation_id', $conversation->id)->where('request_id', $requestId)->where('arguments_hash', $hash)->where('status', 'pending')->exists();
        if (! $existing) {
            if (PluginAction::where('conversation_id', $conversation->id)->where('status', 'pending')->where('expires_at', '>', now())->count() >= (int) config('plugins.max_pending')) {
                return $this->text('Too many changes are already waiting for the person to approve. Ask them to review those first.', true);
            }
            $action = PluginAction::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'request_id' => $requestId, 'app_slug' => $provider->slug(), 'tool' => $name, 'kind' => 'write',
                'status' => 'pending', 'summary' => ActionSummary::for($name, $arguments), 'arguments' => $arguments, 'arguments_hash' => $hash, 'expires_at' => now()->addMinutes((int) config('plugins.pending_minutes'))]);
            Audit::log('plugin.'.$provider->slug().'.proposed', $user, null, ['tool' => $name, 'action_id' => $action->id]);
        }

        return $this->text('This change has not been made. It is waiting for the person to approve it in the chat. Do not call it again. Tell them it is waiting for their approval.', false);
    }

    private function record(Provider $provider, User $user, Conversation $conversation, string $requestId, string $tool, string $kind, string $status, string $summary, ?string $result): void
    {
        $action = PluginAction::create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'request_id' => $requestId, 'app_slug' => $provider->slug(), 'tool' => $tool, 'kind' => $kind,
            'status' => $status, 'summary' => $summary, 'result_summary' => $result !== null ? mb_substr($result, 0, 250) : null, 'executed_at' => now()]);
        Audit::log('plugin.'.$provider->slug().'.'.$kind, $user, null, ['tool' => $tool, 'status' => $status, 'action_id' => $action->id]);
    }

    /** @return array<string, mixed> every refusal gives this same short answer, so nothing about the cause leaks */
    private function refused(Provider $provider): array
    {
        return $this->text($provider->name().' is not available for this request.', true);
    }

    /** @return array<string, mixed> */
    private function text(string $text, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    /** @return array<string, mixed> */
    private function reply(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
}
