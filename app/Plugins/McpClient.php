<?php

namespace App\Plugins;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to an app's remote MCP server as one person (their own token). Each call makes its own short handshake: initialize,
 * then initialized, then the request, carrying the session id the server hands out. Replies are plain JSON or an event stream
 * whose `data:` lines hold the JSON. For GitHub, read-only calls go to its read-only address, which offers no tool that changes anything.
 */
final class McpClient
{
    private const PROTOCOL = '2025-06-18';

    /** The session-free protocol Google Workspace servers use when they may ask the person first. */
    private const ASKING_PROTOCOL = '2026-07-28';

    /** @param  ?string  $url  the server address; GitHub's own (read-only or full) when left out */
    public function __construct(private string $token, private bool $readonly, private ?string $url = null, private string $label = 'GitHub') {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{text: string, is_error: bool}
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed
     */
    public function callTool(string $name, array $arguments): array
    {
        [$url, $session] = $this->open();
        $reply = $this->send($url, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]], $session);

        $result = $reply['body']['result'] ?? null;
        if (! is_array($result)) {
            throw new PluginCallFailed("{$this->label} did not return a result.");
        }
        $text = '';
        foreach ((array) ($result['content'] ?? []) as $part) {
            if (($part['type'] ?? null) === 'text') {
                $text .= ($text === '' ? '' : "\n").(string) $part['text'];
            }
        }

        return ['text' => $text, 'is_error' => (bool) ($result['isError'] ?? false)];
    }

    /**
     * A call to a Google Workspace server that may first ask the person for permission (for example to read mail other people sent).
     * Google's servers keep no session: every call says which protocol it speaks and that it can show a question, and Google answers
     * either with the result or with a question and a state string. The question is returned as `permission`; the answer is sent back
     * with the same tool and arguments plus that state. A server that is never told the client can ask quietly leaves such items out.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array{request_state: string, key: string, choice: string}|null  $answer  the person's answer to an earlier question
     * @return array{text: string, is_error: bool, permission?: array{key: string, message: string, choices: list<array{value: string, label: string}>, request_state: string}}
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed
     */
    public function callToolAskingFirst(string $name, array $arguments, ?array $answer = null): array
    {
        $params = ['name' => $name, 'arguments' => (object) $arguments, '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => self::ASKING_PROTOCOL,
            'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['form' => (object) []]],
        ]];
        if ($answer !== null) {
            $params['requestState'] = $answer['request_state'];
            $params['inputResponses'] = [$answer['key'] => ['action' => 'accept', 'content' => [$answer['key'] => $answer['choice']]]];
        }
        $headers = ['MCP-Protocol-Version' => self::ASKING_PROTOCOL, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name];
        $reply = $this->send((string) $this->url, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $params], null, $headers);

        $result = $reply['body']['result'] ?? null;
        if (! is_array($result)) {
            throw new PluginCallFailed("{$this->label} did not return a result.");
        }
        if (($result['resultType'] ?? null) === 'input_required') {
            return ['text' => '', 'is_error' => false, 'permission' => $this->question($result)];
        }
        $text = '';
        foreach ((array) ($result['content'] ?? []) as $part) {
            if (($part['type'] ?? null) === 'text') {
                $text .= ($text === '' ? '' : "\n").(string) $part['text'];
            }
        }

        return ['text' => $text, 'is_error' => (bool) ($result['isError'] ?? false)];
    }

    /**
     * The one choice question Google sends: a form with a single field whose allowed values are the answers.
     *
     * @param  array<string, mixed>  $result
     * @return array{key: string, message: string, choices: list<array{value: string, label: string}>, request_state: string}
     *
     * @throws PluginCallFailed
     */
    private function question(array $result): array
    {
        $requests = (array) ($result['inputRequests'] ?? []);
        $state = $result['requestState'] ?? null;
        $key = array_key_first($requests);
        $request = $key === null ? null : $requests[$key];
        $field = is_array($request) ? ($request['params']['requestedSchema']['properties'][$key] ?? null) : null;
        if (count($requests) !== 1 || ! is_string($key) || ! is_string($state) || $state === '' || ($request['method'] ?? null) !== 'elicitation/create'
            || ($request['params']['mode'] ?? 'form') !== 'form' || ! is_array($field) || ! is_array($field['enum'] ?? null) || $field['enum'] === []) {
            throw new PluginCallFailed("{$this->label} asked a question Thryve cannot show.");
        }
        $labels = (array) ($field['enumNames'] ?? []);
        $choices = [];
        foreach (array_values($field['enum']) as $i => $value) {
            $choices[] = ['value' => (string) $value, 'label' => mb_substr((string) ($labels[$i] ?? $value), 0, 60)];
        }

        return ['key' => $key, 'message' => mb_substr((string) ($request['params']['message'] ?? ''), 0, 2000), 'choices' => $choices, 'request_state' => $state];
    }

    /**
     * Every tool the server offers, as the server describes them.
     *
     * @return list<array<string, mixed>>
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed
     */
    public function listTools(): array
    {
        [$url, $session] = $this->open();
        $tools = [];
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $reply = $this->send($url, ['jsonrpc' => '2.0', 'id' => 3 + $page, 'method' => 'tools/list', 'params' => $cursor ? ['cursor' => $cursor] : (object) []], $session);
            $result = $reply['body']['result'] ?? null;
            if (! is_array($result)) {
                throw new PluginCallFailed("{$this->label} did not return its tool list.");
            }
            foreach ((array) ($result['tools'] ?? []) as $tool) {
                if (is_array($tool) && is_string($tool['name'] ?? null)) {
                    $tools[] = $tool;
                }
            }
            $cursor = $result['nextCursor'] ?? null;
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return $tools;
    }

    /** @return array{0: string, 1: ?string} the address and the session id after the handshake */
    private function open(): array
    {
        $url = $this->url ?? (string) config($this->readonly ? 'plugins.github.readonly_url' : 'plugins.github.mcp_url');
        $init = $this->send($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => self::PROTOCOL, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'thryve', 'version' => '1']]], null);
        $session = $init['session'];
        $this->send($url, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);

        return [$url, $session];
    }

    /**
     * @param  array<string, string>  $extraHeaders  replace or add headers, such as another protocol version
     * @return array{session: ?string, body: ?array}
     */
    private function send(string $url, array $payload, ?string $session, array $extraHeaders = []): array
    {
        $headers = $extraHeaders + ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => self::PROTOCOL];
        if ($session) {
            $headers['Mcp-Session-Id'] = $session;
        }
        try {
            $response = Http::withToken($this->token)->withHeaders($headers)->acceptJson()->asJson()->timeout(60)->connectTimeout(10)->withoutRedirecting()->post($url, $payload);
        } catch (ConnectionException) {
            throw new PluginCallFailed("{$this->label} could not be reached.");
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new PluginAuthFailed("{$this->label} refused the token.");
        }
        if (! $response->successful()) {
            throw new PluginCallFailed("{$this->label} answered with status {$response->status()}.");
        }
        $body = $response->status() === 202 ? null : $this->parse($response);
        if (is_array($body) && isset($body['error'])) {
            throw new PluginCallFailed("{$this->label} reported an error: ".mb_substr((string) ($body['error']['message'] ?? 'unknown'), 0, 120));
        }

        return ['session' => $response->header('Mcp-Session-Id') ?: $session, 'body' => $body];
    }

    /** @return array<string, mixed>|null */
    private function parse(Response $response): ?array
    {
        if (str_contains((string) $response->header('Content-Type'), 'text/event-stream')) {
            $found = null;
            foreach (preg_split('/\r?\n/', $response->body()) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(substr($line, 5)), true);
                    if (is_array($decoded) && (isset($decoded['result']) || isset($decoded['error']))) {
                        $found = $decoded;
                    }
                }
            }

            return $found;
        }
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }
}
