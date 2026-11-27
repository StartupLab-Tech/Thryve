<?php

namespace Tests\Feature;

use App\Plugins\McpClient;
use App\Plugins\PluginAuthFailed;
use App\Plugins\PluginCallFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Thryve's client for GitHub's remote MCP server: the short handshake, JSON or event-stream replies, and clear failures. */
class McpClientTest extends TestCase
{
    private const DEFAULT_URL = 'https://api.githubcopilot.com/mcp/';

    private const READONLY_URL = 'https://api.githubcopilot.com/mcp/readonly';

    private function stream(array $payload): string
    {
        return "event: message\ndata: ".json_encode($payload)."\n\n";
    }

    /** @param  callable(Request): mixed|null  $callReply */
    private function fakeGitHub(?callable $callReply = null): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($callReply) {
            return match ($request['method']) {
                'initialize' => Http::response($this->stream(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']]), 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'session-1']),
                'notifications/initialized' => Http::response('', 202),
                default => $callReply ? $callReply($request) : Http::response($this->stream(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'two issues']], 'isError' => false]]), 200, ['Content-Type' => 'text/event-stream']),
            };
        });
    }

    public function test_it_does_the_handshake_reads_an_event_stream_reply_and_sends_the_token_and_session(): void
    {
        $this->fakeGitHub();

        $result = (new McpClient('gho_secret', true))->callTool('list_issues', ['owner' => 'octo', 'repo' => 'sandbox']);

        $this->assertSame(['text' => 'two issues', 'is_error' => false], $result);
        $methods = [];
        Http::assertSent(function (Request $request) use (&$methods) {
            $methods[] = $request['method'];
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer gho_secret'));
            $this->assertStringContainsString('text/event-stream', $request->header('Accept')[0]);
            if ($request['method'] !== 'initialize') {
                $this->assertSame(['session-1'], $request->header('Mcp-Session-Id'));
                $this->assertSame(['2025-06-18'], $request->header('MCP-Protocol-Version'));
            }

            return true;
        });
        $this->assertSame(['initialize', 'notifications/initialized', 'tools/call'], $methods);
    }

    public function test_a_plain_json_reply_works_and_empty_arguments_are_sent_as_an_object(): void
    {
        $this->fakeGitHub(function (Request $request) {
            $this->assertStringContainsString('"arguments":{}', $request->body());

            return Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'hello'], ['type' => 'text', 'text' => 'world']], 'isError' => true]]);
        });

        $this->assertSame(['text' => "hello\nworld", 'is_error' => true], (new McpClient('t', true))->callTool('get_me', []));
    }

    public function test_read_only_calls_use_the_read_only_address_and_changes_use_the_normal_one(): void
    {
        $this->fakeGitHub();

        (new McpClient('t', true))->callTool('get_me', []);
        Http::assertSent(fn (Request $r) => $r->url() === self::READONLY_URL);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::DEFAULT_URL);

        (new McpClient('t', false))->callTool('issue_write', ['title' => 'x']);
        Http::assertSent(fn (Request $r) => $r->url() === self::DEFAULT_URL);
    }

    public function test_a_refused_token_is_told_apart_from_other_failures(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 401)]);
        $this->expectException(PluginAuthFailed::class);

        (new McpClient('revoked', true))->callTool('get_me', []);
    }

    public function test_server_errors_and_json_rpc_errors_become_a_short_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('boom', 500)]);
        try {
            (new McpClient('t', true))->callTool('get_me', []);
            $this->fail('A 500 should fail');
        } catch (PluginCallFailed $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $this->fakeGitHub(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32602, 'message' => 'unknown tool']]));
        $this->expectException(PluginCallFailed::class);
        (new McpClient('t', true))->callTool('nope', []);
    }
}
