<?php

use App\Plugins\Providers\GitHubProvider;
use App\Plugins\Providers\GoogleDocsProvider;
use App\Plugins\Providers\GoogleDriveProvider;
use App\Plugins\Providers\GoogleGmailProvider;
use App\Plugins\Providers\GoogleSheetsProvider;

return [
    /** How long a ticket stays valid, in minutes. One ticket is made for each answer. */
    'ticket_minutes' => 30,

    /** How long a change waits for the person's approval before it expires, in minutes. */
    'pending_minutes' => 30,

    /** The most text of one tool result that is handed to the AI, in characters. */
    'result_cap' => 30000,

    /** Changes waiting for approval at the same time, per conversation. */
    'max_pending' => 10,

    /** The plugins this installation offers. Each is a class that implements App\Plugins\Provider; tool names must be unique across them. */
    'providers' => [
        GitHubProvider::class,
        GoogleDriveProvider::class,
        GoogleDocsProvider::class,
        GoogleSheetsProvider::class,
        GoogleGmailProvider::class,
    ],

    /** One OAuth client shared by several plugins: its name on the admin page, and what to tell the admin about it. */
    'shared_oauth' => [
        'google' => [
            'name' => 'Google sign-in',
            'note' => 'One Google Cloud OAuth client signs people in for Drive, Docs, Sheets and Gmail. Create a Web OAuth client (consent screen: External, in Testing, with your testers added; tick "Use this client for an AI-powered agent") and add the callback address below to its authorized redirect URIs.',
        ],
    ],

    'github' => [
        /** Never offered, whatever the snapshot says or an admin switches on. */
        'blocked_tools' => ['delete_repository'],

        /** Offered only after an admin switches them on: they start GitHub Copilot's own cloud agent. */
        'default_off_tools' => ['assign_copilot_to_issue', 'create_pull_request_with_copilot', 'request_copilot_review', 'get_copilot_job_status'],

        'mcp_url' => env('PLUGIN_GITHUB_MCP_URL', 'https://api.githubcopilot.com/mcp/'),
        'readonly_url' => env('PLUGIN_GITHUB_MCP_READONLY_URL', 'https://api.githubcopilot.com/mcp/readonly'),
        'api_url' => env('PLUGIN_GITHUB_API_URL', 'https://api.github.com'),
        'authorize_url' => env('PLUGIN_GITHUB_AUTHORIZE_URL', 'https://github.com/login/oauth/authorize'),
        'token_url' => env('PLUGIN_GITHUB_TOKEN_URL', 'https://github.com/login/oauth/access_token'),
        'scopes' => 'repo read:org read:user',
    ],

    'google' => [
        'authorize_url' => env('PLUGIN_GOOGLE_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('PLUGIN_GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'userinfo_url' => env('PLUGIN_GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
        'revoke_url' => env('PLUGIN_GOOGLE_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        'api_url' => env('PLUGIN_GOOGLE_API_URL', 'https://www.googleapis.com'),

        /** One MCP server per product; {server} is drive, docs or sheets. The "v1self" form is for hosts that cannot show Google's extra input forms. */
        'mcp_url' => env('PLUGIN_GOOGLE_MCP_URL', 'https://{server}mcp.googleapis.com/mcp/v1self'),
    ],
];
