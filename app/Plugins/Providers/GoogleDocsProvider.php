<?php

namespace App\Plugins\Providers;

/** Google Docs: read documents and propose edits. */
final class GoogleDocsProvider extends GoogleProduct
{
    public function slug(): string
    {
        return 'google-docs';
    }

    public function name(): string
    {
        return 'Google Docs';
    }

    public function description(): string
    {
        return 'Read your Google Docs, and propose edits that wait for your approval.';
    }

    protected function server(): string
    {
        return 'docs';
    }

    protected function scopes(): string
    {
        return 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/documents';
    }
}
