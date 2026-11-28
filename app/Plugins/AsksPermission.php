<?php

namespace App\Plugins;

use App\Models\PluginConnection;

/**
 * A plugin whose app may answer a read with a question for the person instead of the result (Google asks before an agent reads mail
 * other people sent). Its `callTool` then returns `permission` (the question, its choices and a state string), and the person's choice
 * is sent back here with the same tool and arguments.
 */
interface AsksPermission
{
    /**
     * @param  array<string, mixed>  $arguments  exactly the arguments of the call that was asked about
     * @param  array{key: string, request_state: string}  $permission  what the app sent with its question
     * @return array{text: string, is_error: bool, permission?: array<string, mixed>}
     *
     * @throws PluginAuthFailed
     * @throws PluginCallFailed
     */
    public function answerPermission(PluginConnection $connection, string $tool, array $arguments, array $permission, string $choice): array;
}
