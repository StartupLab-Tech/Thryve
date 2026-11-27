<?php

namespace App\Plugins;

/** A short readable line for a proposed change, shown on the Approve card. The exact arguments are shown beside it. */
final class ActionSummary
{
    private const SHOWN = ['method', 'title', 'name', 'path', 'branch', 'head', 'base', 'issue_number', 'pullNumber', 'message', 'state', 'body', 'file_id', 'document_id', 'spreadsheet_id', 'range', 'subject', 'to'];

    /** @param  array<string, mixed>  $arguments */
    public static function for(string $tool, array $arguments): string
    {
        $where = '';
        if (isset($arguments['owner'], $arguments['repo'])) {
            $where = ' in '.self::clean((string) $arguments['owner']).'/'.self::clean((string) $arguments['repo']);
        }
        $parts = [];
        foreach (self::SHOWN as $key) {
            if (isset($arguments[$key]) && is_scalar($arguments[$key]) && (string) $arguments[$key] !== '') {
                $parts[] = $key.' "'.mb_substr(self::clean((string) $arguments[$key]), 0, 60).'"';
            }
        }

        return mb_substr($tool.$where.($parts ? ': '.implode(', ', $parts) : ''), 0, 250);
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value)));
    }
}
