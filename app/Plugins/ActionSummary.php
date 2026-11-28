<?php

namespace App\Plugins;

/**
 * Plain words for a change the AI proposed and for what the app said once it was made. The card and the chat line use these, so the
 * person reads "Send draft: subject "Hello", to ann@example.com" and "Sent.", never a tool name or the app's raw JSON.
 */
final class ActionSummary
{
    /** Shown details, in this order, with the label the person sees. */
    private const SHOWN = [
        'title' => 'title', 'name' => 'name', 'subject' => 'subject', 'to' => 'to', 'path' => 'file', 'branch' => 'branch', 'head' => 'from branch',
        'base' => 'into branch', 'issue_number' => 'issue #', 'pullNumber' => 'pull request #', 'state' => 'state', 'message' => 'message',
        'body' => 'text', 'text' => 'text', 'file_id' => 'file', 'document_id' => 'document', 'spreadsheet_id' => 'spreadsheet', 'range' => 'cells',
    ];

    /** Where a link to the result usually sits in an app's reply. */
    private const LINKS = ['viewUrl', 'webViewLink', 'alternateLink', 'html_url', 'htmlUrl', 'url', 'link'];

    /** @param  array<string, mixed>  $arguments */
    public static function for(string $tool, array $arguments): string
    {
        $where = '';
        if (isset($arguments['owner'], $arguments['repo'])) {
            $where = ' in '.self::clean((string) $arguments['owner']).'/'.self::clean((string) $arguments['repo']);
        }
        $parts = [];
        foreach (self::SHOWN as $key => $label) {
            if (isset($arguments[$key]) && is_scalar($arguments[$key]) && (string) $arguments[$key] !== '') {
                $value = mb_substr(self::clean((string) $arguments[$key]), 0, 60);
                $parts[] = str_ends_with($label, '#') ? $label.$value : $label.' "'.$value.'"';
            }
        }

        return mb_substr(self::action($tool, $arguments).$where.($parts ? ': '.implode(', ', $parts) : ''), 0, 250);
    }

    /**
     * What the app answered, in plain words: "Sent.", "Moved to the trash." or "Done", the item's name and a link to open it.
     * A reply that is not JSON is the app's own sentence and is kept, shortened.
     */
    public static function outcome(string $text): string
    {
        $data = json_decode(trim($text), true);
        if (! is_array($data)) {
            $line = self::clean($text);

            return $line === '' ? 'Done.' : mb_substr($line, 0, 200);
        }
        $done = match (true) {
            ($data['sent'] ?? false) === true => 'Sent.',
            ($data['trashed'] ?? false) === true => 'Moved to the trash.',
            ($data['deleted'] ?? false) === true => 'Deleted.',
            default => 'Done.',
        };
        foreach (['title', 'name', 'subject'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                $done .= ' "'.mb_substr(self::clean((string) $data[$key]), 0, 80).'"';
                break;
            }
        }
        foreach (self::LINKS as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && preg_match('#^https://\S+$#', $data[$key])) {
                return $done.' Open it: '.$data[$key];
            }
        }

        return $done;
    }

    /** "google_gmail_send_draft" becomes "Send draft"; GitHub's "issue_write" with method "create" becomes "Create issue". */
    private static function action(string $tool, array $arguments): string
    {
        $words = (string) preg_replace('/^(google_[a-z]+_|github_)/', '', $tool);
        if (isset($arguments['method']) && is_string($arguments['method']) && preg_match('/^(.+)_(write|update)$/', $words, $m)) {
            $words = $arguments['method'].'_'.$m[1];
        }

        return ucfirst(strtolower(trim((string) preg_replace('/[_\s]+/', ' ', self::clean($words)))));
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value)));
    }
}
