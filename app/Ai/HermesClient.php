<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Hermes (the AI service on the company VPS) from the server only: the browser never sees the address or key.
 *
 * IMPORTANT: the Hermes request contract is not known yet (plan decision D02). This client therefore only does what
 * is safe without it: it checks that the server answers and whether it accepts the access key. Sending prompts and
 * reading streamed answers and token counts is added in P05 once the contract is confirmed.
 */
class HermesClient
{
    public function stream(array $messages, ?array $target, ?string $sessionId, callable $emit, callable $stop, ?callable $status = null): array
    {
        if ($target === null && (app(AgentService::class)->any() || ! $this->configured())) {
            throw new ProviderException('unavailable');
        }
        $decoder = new StreamDecoder(\Closure::fromCallable($emit), $status ? \Closure::fromCallable($status) : null);
        // A streamed answer is not held to the short single-request timeout: by default Thryve waits until Hermes finishes.
        $cap = (int) config('services.hermes.stream_max_seconds', 0);
        $timeout = $cap > 0 ? max(30, $cap) : 0;
        $finish = null;
        try {
            if ($stop()) {
                return ['content' => '', 'input' => 0, 'output' => 0, 'estimate' => false, 'finish' => 'cancelled'];
            }
            $headers = [];
            if ($key = $target['key'] ?? config('services.hermes.key')) {
                $headers[] = 'Authorization: Bearer '.$key;
            }
            // No X-Hermes-Session-Id: Hermes then ignores the history sent below and the agent forgets earlier turns.
            // A chosen model is sent with its provider: Hermes ignores a model name that comes without one.
            $payload = ['model' => $target['model'] ?? config('services.hermes.model'), 'messages' => $messages, 'stream' => true, 'stream_options' => ['include_usage' => true]];
            if (filled($target['provider'] ?? null)) {
                $payload['provider'] = $target['provider'];
            }
            $cancelled = app(StreamTransport::class)->read(rtrim($target['url'] ?? (string) config('services.hermes.url'), '/').'/v1/chat/completions', $headers, $payload, $timeout, fn ($bytes) => $decoder->feed($bytes), $stop);
            if ($cancelled) {
                $finish = 'cancelled';
            }
            if ($finish !== 'cancelled' && $stop()) {
                $finish = 'cancelled';
            }
            if ($finish !== 'cancelled' && (! $decoder->done || trim($decoder->content) === '')) {
                throw new ProviderException('bad_response', ambiguous: true);
            }
            $usage = $decoder->usage;
            $estimate = $usage === null;

            $result = ['content' => $decoder->content, 'input' => $usage['input'] ?? (int) ceil(strlen(json_encode($messages)) / 4), 'output' => $usage['output'] ?? (int) ceil(strlen($decoder->content) / 4), 'estimate' => $estimate, 'finish' => $finish ?? $decoder->finish, 'model' => $target['model'] ?? null];
        } catch (ProviderException $e) {
            if ($e->ambiguous || $decoder->content !== '') {
                throw new ProviderException($e->category, $e->retryable, true, $decoder->usage['input'] ?? (int) ceil(strlen(json_encode($messages)) / 4), $decoder->usage['output'] ?? (int) ceil(strlen($decoder->content) / 4), $decoder->content, $decoder->usage === null);
            }
            throw $e;
        } catch (ConnectionException) {
            throw new ProviderException('timeout', ambiguous: true, partialContent: $decoder->content);
        }

        $this->rejectProviderNotice($result['content']);

        return $result;
    }

    /**
     * Hermes sometimes reports a provider failure (for example a usage limit) as ordinary answer text with a normal finish.
     * Treat that as a failed call so employees never see provider or tooling details and nothing is saved as an answer.
     */
    public static function looksLikeProviderNotice(string $content): bool
    {
        return strlen($content) < 1500
            && preg_match('/rate-limited every one of \d+ attempts|Provider said: HTTP \d{3}|hermes fallback add|Send \/retry after that/i', $content) === 1;
    }

    private function rejectProviderNotice(string $content): void
    {
        if (self::looksLikeProviderNotice($content)) {
            throw new ProviderException(self::classifyError($content), retryable: true, ambiguous: false, inputTokens: 0, outputTokens: 0, partialContent: '');
        }
    }

    public function configured(): bool
    {
        return filled(config('services.hermes.url'));
    }

    /**
     * @return array{ok: bool, category: string, status: int|null}
     *                                                             category: ok | unauthorized | rate_limited | unavailable | timeout | not_configured
     */
    public function ping(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'category' => 'not_configured', 'status' => null];
        }

        try {
            $request = Http::timeout(5)->connectTimeout(3)->withoutRedirecting();
            if ($key = config('services.hermes.key')) {
                $request = $request->withToken($key);
            }
            $status = $request->get(config('services.hermes.url'))->status();
        } catch (ConnectionException $e) {
            // The raw message can contain the address, so only a category leaves this method.
            $timeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');

            return ['ok' => false, 'category' => $timeout ? 'timeout' : 'unavailable', 'status' => null];
        } catch (\Throwable) {
            return ['ok' => false, 'category' => 'unavailable', 'status' => null];
        }

        return match (true) {
            in_array($status, [401, 403], true) => ['ok' => false, 'category' => 'unauthorized', 'status' => $status],
            $status === 429 => ['ok' => false, 'category' => 'rate_limited', 'status' => $status],
            $status >= 500 => ['ok' => false, 'category' => 'unavailable', 'status' => $status],
            default => ['ok' => true, 'category' => 'ok', 'status' => $status], // any other answer means the server is up
        };
    }

    /**
     * Free connection check against a Hermes profile address: GET /v1/models needs the access key but spends no tokens,
     * so it tells "unreachable" from "key rejected" from "profile not found".
     *
     * @return array{ok: bool, category: string, status: int|null, latency_ms: int|null}
     *                                                                                   category: ok | unauthorized | profile_missing | rate_limited | unavailable | timeout | bad_response
     */
    public function probe(string $url, ?string $key, ?string $profile = null): array
    {
        $t0 = hrtime(true);
        $ms = fn () => (int) ((hrtime(true) - $t0) / 1e6);
        try {
            $request = Http::timeout(8)->connectTimeout(4)->withoutRedirecting()->acceptJson();
            if ($key) {
                $request = $request->withToken($key);
            }
            $response = $request->get(rtrim($url, '/').'/v1/models');
        } catch (ConnectionException $e) {
            // The raw message can contain the address, so only a category leaves this method.
            $text = strtolower($e->getMessage());

            return ['ok' => false, 'category' => str_contains($text, 'timed out') || str_contains($text, 'timeout') ? 'timeout' : 'unavailable', 'status' => null, 'latency_ms' => $ms()];
        } catch (\Throwable) {
            return ['ok' => false, 'category' => 'unavailable', 'status' => null, 'latency_ms' => $ms()];
        }

        $status = $response->status();
        $out = fn (bool $ok, string $category) => ['ok' => $ok, 'category' => $category, 'status' => $status, 'latency_ms' => $ms()];

        return match (true) {
            in_array($status, [401, 403], true) => $out(false, 'unauthorized'),
            $status === 429 => $out(false, 'rate_limited'),
            $status === 404 => $out(false, 'profile_missing'),
            in_array($status, [301, 302, 303, 307, 308], true) => $out(false, 'login_page'), // a web login or redirect, not the API
            $status !== 200 => $out(false, 'unavailable'),
            default => $this->checkModels($response->json(), $profile, $out),
        };
    }

    private function checkModels(mixed $body, ?string $profile, \Closure $out): array
    {
        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            return $out(false, 'bad_response');
        }
        if ($profile && ! in_array($profile, array_column($body['data'], 'id'), true)) {
            return $out(false, 'profile_missing');
        }

        return $out(true, 'ok');
    }

    /**
     * The providers and models this Hermes profile can reach right now (GET /api/model/options with the agent's key).
     * Spends no tokens. Null when it cannot be read; only the decoded list ever leaves, never an address or a message.
     *
     * @return array<string, mixed>|null
     */
    public function modelOptions(string $url, ?string $key): ?array
    {
        try {
            $request = Http::timeout(8)->connectTimeout(4)->withoutRedirecting()->acceptJson();
            if ($key) {
                $request = $request->withToken($key);
            }
            $response = $request->get(rtrim($url, '/').'/api/model/options');
        } catch (\Throwable) {
            return null;
        }
        $body = $response->status() === 200 ? $response->json() : null;

        return is_array($body) && is_array($body['providers'] ?? null) ? $body : null;
    }

    /**
     * Send a conversation and wait for the full answer (non-streaming).
     *
     * Contract observed against a live Hermes gateway: success is a standard chat.completion with real `usage`.
     * A failure inside Hermes (provider rate limit, bad model, ...) still comes back as HTTP 200, with
     * finish_reason "error", zero usage, the error text inside the assistant message, and a `hermes` block flagging it.
     * Raw provider text is only inspected to pick a category; it never reaches an exception message or a log.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, input: int, output: int, estimate: bool, finish: string|null, model: string|null}
     *
     * @throws ProviderException
     */
    public function chat(array $messages, ?array $target = null, ?string $sessionId = null): array
    {
        // The caller names the agent to talk to. With no agent at all, the old .env settings still work.
        if ($target === null && (app(AgentService::class)->any() || ! $this->configured())) {
            throw new ProviderException('unavailable', retryable: true);
        }

        $key = $target['key'] ?? config('services.hermes.key');
        $url = rtrim($target['url'] ?? (string) config('services.hermes.url'), '/').'/v1/chat/completions';
        $model = $target['model'] ?? config('services.hermes.model');

        try {
            $request = Http::timeout((int) config('services.hermes.timeout'))->connectTimeout(5)->withoutRedirecting()->acceptJson();
            if ($key) {
                $request = $request->withToken($key);
            }
            // No X-Hermes-Session-Id: Hermes then ignores the history sent below and the agent forgets earlier turns.
            $body = ['model' => $model, 'messages' => $messages, 'stream' => false];
            // A chosen model is sent with its provider: Hermes ignores a model name that comes without one.
            if (filled($target['provider'] ?? null)) {
                $body['provider'] = $target['provider'];
            }
            $response = $request->post($url, $body);
        } catch (ConnectionException $e) {
            $text = strtolower($e->getMessage());
            // A read timeout means the request was sent and may still be running: it must not be repeated elsewhere.
            if (str_contains($text, 'timed out') && ! str_contains($text, 'connection timed out after') && ! str_contains($text, 'could not connect')) {
                throw new ProviderException('timeout', retryable: true, ambiguous: true);
            }
            throw new ProviderException('unavailable', retryable: true);
        }

        $status = $response->status();
        if ($status !== 200) {
            throw match (true) {
                in_array($status, [401, 403], true) => new ProviderException('unauthorized', retryable: true),
                $status === 429 => new ProviderException('rate_limited', retryable: true),
                $status >= 300 && $status < 400 => new ProviderException('unavailable', retryable: true), // answered with a login page: nothing was run
                $status >= 500 => new ProviderException('unavailable', retryable: true, ambiguous: true),
                default => new ProviderException('bad_request', retryable: false),
            };
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new ProviderException('bad_response', retryable: true);
        }

        $choice = $body['choices'][0] ?? [];
        $content = (string) ($choice['message']['content'] ?? '');
        $finish = $choice['finish_reason'] ?? null;
        $input = (int) ($body['usage']['prompt_tokens'] ?? 0);
        $output = (int) ($body['usage']['completion_tokens'] ?? 0);

        if (($body['hermes']['failed'] ?? false) === true || $finish === 'error') {
            // Hermes put the provider's error into the answer text. Classify from it, carry tokens only if any were billed.
            $category = self::classifyError(($body['hermes']['error'] ?? '').' '.($body['hermes']['error_code'] ?? '').' '.$content);
            $billed = $input + $output > 0;
            throw new ProviderException(
                $category,
                retryable: $category !== 'bad_request',
                ambiguous: $billed,
                inputTokens: $billed ? $input : 0,
                outputTokens: $billed ? $output : 0,
            );
        }

        if (trim($content) === '') {
            throw new ProviderException('bad_response', retryable: true, ambiguous: $input + $output > 0, inputTokens: $input, outputTokens: $output);
        }

        $this->rejectProviderNotice($content);

        $estimate = false;
        if ($input + $output === 0) {
            // Usage missing: estimate (about four characters per token) and flag it so reports can show "estimated".
            $estimate = true;
            $input = (int) ceil(strlen(json_encode($messages)) / 4);
            $output = (int) ceil(strlen($content) / 4);
        }

        return [
            'content' => $content,
            'input' => $input,
            'output' => $output,
            'estimate' => $estimate,
            'finish' => $finish,
            'model' => $body['model'] ?? null,
        ];
    }

    /** Map Hermes' error text to a router category. Order matters: the most specific signals first. */
    private static function classifyError(string $text): string
    {
        $t = strtolower($text);

        return match (true) {
            str_contains($t, 'quota') || str_contains($t, 'insufficient') || str_contains($t, 'billing') => 'quota_exhausted',
            str_contains($t, '429') || str_contains($t, 'rate limit') || str_contains($t, 'rate_limit') => 'rate_limited',
            str_contains($t, '401') || str_contains($t, '403') || str_contains($t, 'unauthor') || str_contains($t, 'forbidden') || str_contains($t, 'expired') => 'unauthorized',
            str_contains($t, '400') || str_contains($t, '404') || str_contains($t, 'not found') || str_contains($t, 'invalid') => 'bad_request',
            default => 'unavailable',
        };
    }

    /** Turn a ping result into the exception the router understands. */
    public static function toException(array $ping): ?ProviderException
    {
        return match ($ping['category']) {
            'ok' => null,
            'unauthorized' => new ProviderException('unauthorized', retryable: true),
            'rate_limited' => new ProviderException('rate_limited', retryable: true),
            'timeout' => new ProviderException('timeout', retryable: true),
            default => new ProviderException('unavailable', retryable: true),
        };
    }
}
