<?php

namespace App\Plugins\Providers;

/**
 * Gmail: search and read mail, and create or update drafts. Nothing else is offered: only the tools that search, get or list, and the ones
 * that create or update a draft. Sending (the compose permission would allow it), labelling, spam, trash and filters are never offered,
 * whatever an admin switches on, and a tool Google adds later stays off until it is added here. People send their drafts themselves in Gmail.
 */
final class GoogleGmailProvider extends GoogleProduct
{
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
        return 'Search and read your email, and create drafts that wait for your approval. It never sends mail for you.';
    }

    public function adminNote(): ?string
    {
        return 'Gmail asks Google for the restricted "Gmail read" and "Gmail compose" permissions. In Google Cloud, enable the Gmail API and the Gmail MCP API, and add both permissions under Data Access. While the Google app is in Testing, your listed testers can use them without Google\'s review. Mail is private: switch Gmail on only for the chatbots that should see it.';
    }

    public function blocks(string $tool): bool
    {
        $allowed = preg_match('/^google_gmail_(search|get|list)_/', $tool) === 1 || preg_match('/^google_gmail_(create|update)_draft$/', $tool) === 1;

        return ! $allowed || preg_match('/send/i', $tool) === 1 || parent::blocks($tool);
    }

    public function blockedTools(): array
    {
        return array_values(array_filter(array_keys($this->tools()), fn (string $name) => $this->blocks($name)));
    }

    protected function server(): string
    {
        return 'gmail';
    }

    protected function scopes(): string
    {
        return 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.compose';
    }
}
