<?php

namespace App\Plugins\Providers;

/** Google Sheets: read spreadsheets and propose changes to cells. */
final class GoogleSheetsProvider extends GoogleProduct
{
    public function slug(): string
    {
        return 'google-sheets';
    }

    public function name(): string
    {
        return 'Google Sheets';
    }

    public function description(): string
    {
        return 'Read your Google Sheets, and propose changes to cells and formulas that wait for your approval.';
    }

    protected function server(): string
    {
        return 'sheets';
    }

    protected function scopes(): string
    {
        return 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/spreadsheets';
    }
}
