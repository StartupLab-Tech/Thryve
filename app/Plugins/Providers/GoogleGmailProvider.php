<?php

namespace App\Plugins\Providers;

use App\Models\PluginAction;
use App\Models\PluginConnection;
use App\Plugins\PluginAuthFailed;
use App\Plugins\PluginCallFailed;
use App\Plugins\PreviewsChanges;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gmail: search and read mail, create or update drafts, and, only when an admin switches it on, send a draft the person has approved.
 * Only the tools that search, get or list and the ones that create or update a draft are offered from Google's list; labelling, spam,
 * trash and filters are never offered, and a tool Google adds later stays off until it is added here.
 *
 * Sending is Thryve's own tool (Google's server offers none). It sends only a draft that already exists. The person is shown the
 * recipients, subject and text read from the real draft in Gmail, never the AI's description of it, and if the draft changes after that
 * it is not sent. It is off until an admin allows it, and each person has a daily limit.
 */
final class GoogleGmailProvider extends GoogleProduct implements PreviewsChanges
{
    public const SEND = 'google_gmail_send_draft';

    public function slug(): string
    {
        return 'google-gmail';
    }

    public function name(): string
    {
        return 'Gmail';
    }

    public function description(): string
    {
        return 'Search and read your email, and create drafts that wait for your approval. Sending a draft is off until an admin allows it, and it always asks you first.';
    }

    public function adminNote(): ?string
    {
        return 'Gmail asks Google for the restricted "Gmail read" and "Gmail compose" permissions. In Google Cloud, enable the Gmail API and the Gmail MCP API, and add both permissions under Data Access. While the Google app is in Testing, your listed testers can use them without Google\'s review. Mail is private: switch Gmail on only for the chatbots that should see it. Sending is a separate switch in the tool list below ("google_gmail_send_draft"), off by default: it sends only a draft that already exists, after the person has seen its real recipients and text and clicked Approve, up to a daily limit.';
    }

    public function tools(): array
    {
        return parent::tools() + [self::SEND => [
            'name' => self::SEND, 'remote' => '', 'server' => 'gmail', 'kind' => 'write',
            'description' => 'Sends a Gmail draft that already exists. The person is shown the real recipients, subject and text and must approve before anything is sent. Create the draft first, then pass its draftId. Never call this without a draft.',
            'inputSchema' => ['type' => 'object', 'properties' => ['draftId' => ['type' => 'string', 'description' => 'The id of the draft to send.']], 'required' => ['draftId']],
        ]];
    }

    public function defaultOffTools(): array
    {
        return [self::SEND, ...parent::defaultOffTools()];
    }

    public function blocks(string $tool): bool
    {
        if ($tool === self::SEND) {
            return parent::blocks($tool);
        }
        $allowed = preg_match('/^google_gmail_(search|get|list)_/', $tool) === 1 || preg_match('/^google_gmail_(create|update)_draft$/', $tool) === 1;

        return ! $allowed || preg_match('/send/i', $tool) === 1 || parent::blocks($tool);
    }

    public function blockedTools(): array
    {
        return array_values(array_filter(array_keys($this->tools()), fn (string $name) => $this->blocks($name)));
    }

    public function previewChange(PluginConnection $connection, string $tool, array $arguments): array
    {
        if ($tool !== self::SEND) {
            return $arguments;
        }
        $this->assertUnderDailyLimit($connection);
        $draft = $this->readDraft($connection, $this->draftId($arguments));

        return array_filter([
            'draftId' => $draft['id'], 'to' => $draft['to'], 'cc' => $draft['cc'], 'bcc' => $draft['bcc'], 'subject' => $draft['subject'],
            'attachments' => $draft['attachments'], 'body_preview' => $draft['preview'], '_fingerprint' => $draft['fingerprint'],
        ], fn ($value) => $value !== '' && $value !== null);
    }

    public function callTool(PluginConnection $connection, string $tool, array $arguments, bool $readOnly): array
    {
        if ($tool !== self::SEND) {
            return parent::callTool($connection, $tool, $arguments, $readOnly);
        }
        $this->assertUnderDailyLimit($connection);
        $draft = $this->readDraft($connection, $this->draftId($arguments));
        if (! hash_equals((string) ($arguments['_fingerprint'] ?? ''), $draft['fingerprint'])) {
            return ['text' => 'The draft was changed after you were asked to approve it, so it was not sent. Ask again to review the new version.', 'is_error' => true];
        }

        return $this->withFreshToken($connection, function (string $token) use ($draft) {
            try {
                $response = Http::withToken($token)->acceptJson()->asJson()->timeout(30)->connectTimeout(10)->post($this->api('/drafts/send'), ['id' => $draft['id']]);
            } catch (ConnectionException) {
                throw new PluginCallFailed('Gmail could not be reached.');
            }
            if ($response->status() === 401) {
                throw new PluginAuthFailed('Google refused the token.');
            }
            if (! $response->successful()) {
                return ['text' => 'Gmail could not send the draft: '.mb_substr((string) $response->json('error.message', 'status '.$response->status()), 0, 160), 'is_error' => true];
            }

            return ['text' => json_encode(['sent' => true, 'id' => $response->json('id'), 'threadId' => $response->json('threadId'), 'labels' => $response->json('labelIds')]), 'is_error' => false];
        });
    }

    protected function server(): string
    {
        return 'gmail';
    }

    protected function scopes(): string
    {
        return 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.compose';
    }

    private function api(string $path): string
    {
        return rtrim((string) config('plugins.google.gmail_api_url'), '/').'/gmail/v1/users/me'.$path;
    }

    /** @param  array<string, mixed>  $arguments */
    private function draftId(array $arguments): string
    {
        $id = (string) ($arguments['draftId'] ?? '');
        if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) !== 1) {
            throw new PluginCallFailed('No valid draft id was given. Create the draft first and pass its draftId.');
        }

        return $id;
    }

    /** @throws PluginCallFailed */
    private function assertUnderDailyLimit(PluginConnection $connection): void
    {
        $limit = (int) config('plugins.google.gmail_daily_send_limit');
        $sent = PluginAction::where('user_id', $connection->user_id)->where('tool', self::SEND)->where('status', 'done')->where('executed_at', '>=', now()->startOfDay())->count();
        if ($sent >= $limit) {
            throw new PluginCallFailed("The daily limit of {$limit} sent emails has been reached. It resets at midnight.");
        }
    }

    /**
     * The draft as Gmail has it right now: who it goes to, the subject and the text. The fingerprint covers all of it, so a change made
     * after the person looked at it is noticed before anything is sent.
     *
     * @return array{id: string, to: string, cc: string, bcc: string, subject: string, attachments: string, preview: string, fingerprint: string}
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed
     */
    private function readDraft(PluginConnection $connection, string $id): array
    {
        $response = $this->withFreshToken($connection, function (string $token) use ($id) {
            try {
                return Http::withToken($token)->acceptJson()->timeout(30)->connectTimeout(10)->get($this->api("/drafts/{$id}"), ['format' => 'full']);
            } catch (ConnectionException) {
                throw new PluginCallFailed('Gmail could not be reached.');
            }
        });
        if (in_array($response->status(), [401, 403], true)) {
            throw new PluginAuthFailed('Google refused the token.');
        }
        if ($response->status() === 404) {
            throw new PluginCallFailed('That draft was not found. Create it first, then try again.');
        }
        if (! $response->successful()) {
            throw new PluginCallFailed("Gmail answered with status {$response->status()}.");
        }
        $payload = (array) $response->json('message.payload', []);
        $headers = [];
        foreach ((array) ($payload['headers'] ?? []) as $header) {
            $headers[strtolower((string) ($header['name'] ?? ''))] = trim((string) ($header['value'] ?? ''));
        }
        $to = $headers['to'] ?? '';
        $cc = $headers['cc'] ?? '';
        $bcc = $headers['bcc'] ?? '';
        if ($to === '' && $cc === '' && $bcc === '') {
            throw new PluginCallFailed('The draft has no recipient, so it cannot be sent.');
        }
        $text = '';
        $attachments = [];
        $this->walk($payload, $text, $attachments);
        $text = trim($text);
        $preview = mb_strlen($text) > 1500 ? mb_substr($text, 0, 1500).' [shortened]' : $text;

        return [
            'id' => (string) $response->json('id', $id), 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $headers['subject'] ?? '(no subject)',
            'attachments' => implode(', ', $attachments), 'preview' => $preview,
            'fingerprint' => hash('sha256', json_encode([$to, $cc, $bcc, $headers['subject'] ?? '', $text, $attachments], JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * Collects the readable text (plain text, or HTML with its tags removed when there is no plain part) and the attachment names.
     *
     * @param  array<string, mixed>  $part
     * @param  list<string>  $attachments
     */
    private function walk(array $part, string &$text, array &$attachments, bool $plainSibling = false): void
    {
        $name = (string) ($part['filename'] ?? '');
        if ($name !== '') {
            $attachments[] = $name;

            return;
        }
        $data = (string) ($part['body']['data'] ?? '');
        $mime = (string) ($part['mimeType'] ?? '');
        if ($data !== '' && $mime === 'text/plain') {
            $text .= (string) base64_decode(strtr($data, '-_', '+/'));
        } elseif ($data !== '' && $mime === 'text/html' && ! $plainSibling) {
            $text .= trim(html_entity_decode(strip_tags((string) base64_decode(strtr($data, '-_', '+/')))));
        }
        $children = (array) ($part['parts'] ?? []);
        $hasPlain = $this->hasPlainPart($children);
        foreach ($children as $child) {
            $this->walk((array) $child, $text, $attachments, $hasPlain);
        }
    }

    /** @param  array<int, mixed>  $parts */
    private function hasPlainPart(array $parts): bool
    {
        foreach ($parts as $part) {
            if (($part['mimeType'] ?? '') === 'text/plain') {
                return true;
            }
        }

        return false;
    }
}
