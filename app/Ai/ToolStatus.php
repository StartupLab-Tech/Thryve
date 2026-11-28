<?php

namespace App\Ai;

/**
 * The live status line shown while the agent works: a tool name Hermes reports becomes a short plain sentence ("Searching Gmail").
 * Only the tool's name is used, never its arguments, so nothing the agent sends (a plugin ticket, a file path) reaches the screen.
 */
final class ToolStatus
{
    /** Plugin apps, by the word their tool names carry. */
    private const APPS = ['gmail' => 'Gmail', 'drive' => 'Google Drive', 'docs' => 'Google Docs', 'sheets' => 'Google Sheets', 'calendar' => 'Google Calendar', 'github' => 'GitHub'];

    /** Hermes's own tools, by the start of their name. */
    private const OWN = [
        'web_search' => 'Searching the web', 'web_extract' => 'Reading a web page', 'web_crawl' => 'Reading a web page', 'browser' => 'Browsing the web',
        'terminal' => 'Running a command', 'execute_code' => 'Running code', 'process' => 'Running a command', 'read_file' => 'Reading a file',
        'search_files' => 'Looking through files', 'write_file' => 'Writing a file', 'patch' => 'Editing a file', 'vision' => 'Looking at the image',
        'image_generate' => 'Creating an image', 'memory' => 'Checking memory', 'session_search' => 'Looking through past chats', 'skill' => 'Using a skill',
        'todo' => 'Planning the steps', 'delegate' => 'Handing part of the work to a helper', 'clarify' => 'Preparing a question for you',
    ];

    private const READS = ['search', 'list', 'get', 'read', 'fetch', 'find', 'query', 'download', 'export'];

    public static function label(string $tool): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '_', $tool));
        foreach (self::APPS as $word => $app) {
            if (preg_match('/(^|_)'.$word.'(_|$)/', $name)) {
                $action = (string) preg_replace('/^.*?(^|_)'.$word.'_/', '', $name);
                foreach (self::READS as $read) {
                    if (str_starts_with($action, $read)) {
                        return $read === 'search' || $read === 'find' || $read === 'query' ? "Searching {$app}" : "Checking {$app}";
                    }
                }

                return "Preparing a change in {$app}";
            }
        }
        foreach (self::OWN as $start => $label) {
            if (str_starts_with($name, $start)) {
                return $label;
            }
        }

        return 'Using a tool';
    }
}
