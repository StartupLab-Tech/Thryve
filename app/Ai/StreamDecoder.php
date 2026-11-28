<?php

namespace App\Ai;

/** Bounded OpenAI-compatible SSE decoder; transport chunk boundaries are unrelated to event boundaries. */
final class StreamDecoder
{
    private string $buffer = '';

    public string $content = '';

    public ?array $usage = null;

    public ?string $finish = null;

    public bool $done = false;

    private int $bytes = 0;

    /** @param  \Closure(string): void|null  $status  gets a plain status line each time Hermes starts or finishes a tool */
    public function __construct(private \Closure $emit, private ?\Closure $status = null) {}

    public function feed(string $bytes): void
    {
        $this->bytes += strlen($bytes);
        if ($this->bytes > 2 * 1024 * 1024) {
            throw new ProviderException('bad_response', ambiguous: true);
        }
        $this->buffer .= $bytes;
        while (preg_match('/\r?\n\r?\n/', $this->buffer, $boundary, PREG_OFFSET_CAPTURE)) {
            if ($this->done) {
                break;
            }
            $at = $boundary[0][1];
            $event = substr($this->buffer, 0, $at);
            $this->buffer = substr($this->buffer, $at + strlen($boundary[0][0]));
            $data = [];
            $type = 'message';
            foreach (preg_split('/\r?\n/', $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5), ' ');
                } elseif (str_starts_with($line, 'event:')) {
                    $type = trim(substr($line, 6));
                }
            }
            if (! $data) {
                continue;
            }$raw = implode("\n", $data);
            if ($raw === '[DONE]') {
                $this->done = true;

                continue;
            }
            $body = json_decode($raw, true);
            if (! is_array($body)) {
                throw new ProviderException('bad_response', ambiguous: true);
            }
            if ($type === 'hermes.tool.progress') {
                if ($this->status && is_string($body['tool'] ?? null)) {
                    ($this->status)(($body['status'] ?? null) === 'running' ? ToolStatus::label($body['tool']) : 'Thinking about the results');
                }

                continue;
            }
            $choice = $body['choices'][0] ?? [];
            if ($type === 'error' || isset($body['error']) || ($body['hermes']['failed'] ?? false) || ($choice['finish_reason'] ?? null) === 'error') {
                throw new ProviderException('unavailable', ambiguous: true);
            }
            if (isset($body['usage']['prompt_tokens'],$body['usage']['completion_tokens'])) {
                $this->usage = ['input' => max(0, (int) $body['usage']['prompt_tokens']), 'output' => max(0, (int) $body['usage']['completion_tokens'])];
            }
            if (isset($choice['finish_reason'])) {
                $this->finish = $choice['finish_reason'];
            }
            $part = $choice['delta']['content'] ?? '';
            if (! is_string($part)) {
                throw new ProviderException('bad_response', ambiguous: true);
            }
            if ($part !== '') {
                $this->content .= $part;
                ($this->emit)($part);
            }
        }
        if (strlen($this->buffer) > 262144) {
            throw new ProviderException('bad_response', ambiguous: true);
        }
    }
}
