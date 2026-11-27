<?php

namespace App\Plugins\Providers;

use App\Models\PluginConnection;
use App\Plugins\PluginAuthFailed;
use App\Plugins\PluginCallFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Google Drive: find, read and organise files. */
final class GoogleDriveProvider extends GoogleProduct
{
    public function slug(): string
    {
        return 'google-drive';
    }

    public function name(): string
    {
        return 'Google Drive';
    }

    public function description(): string
    {
        return 'Search and read your Drive files, and propose new files, copies and moves that wait for your approval.';
    }

    /**
     * Google's own "trash file" tool only runs for a client that can show Google's confirmation form, which Thryve cannot. The person has
     * already confirmed the exact file in Thryve's Approve card, so that one action goes to the Drive API instead: the file is moved to the
     * trash (not deleted for good), and only a file the app may touch can be moved.
     */
    public function callTool(PluginConnection $connection, string $tool, array $arguments, bool $readOnly): array
    {
        if (($this->tools()[$tool]['remote'] ?? null) !== 'trash_file') {
            return parent::callTool($connection, $tool, $arguments, $readOnly);
        }
        $fileId = (string) ($arguments['fileId'] ?? $arguments['file_id'] ?? '');
        if ($fileId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $fileId) !== 1) {
            return ['text' => 'No valid file id was given.', 'is_error' => true];
        }

        return $this->withFreshToken($connection, function (string $token) use ($fileId) {
            try {
                $response = Http::withToken($token)->acceptJson()->asJson()->timeout(30)->connectTimeout(10)
                    ->patch(rtrim((string) config('plugins.google.api_url'), '/')."/drive/v3/files/{$fileId}?supportsAllDrives=true&fields=id,name,trashed", ['trashed' => true]);
            } catch (ConnectionException) {
                throw new PluginCallFailed('Google could not be reached.');
            }
            if ($response->status() === 401) {
                throw new PluginAuthFailed('Google refused the token.');
            }
            if (! $response->successful()) {
                return ['text' => 'Google could not move the file to the trash: '.mb_substr((string) $response->json('error.message', 'status '.$response->status()), 0, 160), 'is_error' => true];
            }

            return ['text' => json_encode(['id' => $response->json('id'), 'name' => $response->json('name'), 'trashed' => (bool) $response->json('trashed')]), 'is_error' => false];
        });
    }

    protected function server(): string
    {
        return 'drive';
    }

    protected function scopes(): string
    {
        return 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file';
    }
}
