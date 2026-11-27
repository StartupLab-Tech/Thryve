<?php

namespace App\Plugins;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * A short code made for each answer. Hermes cannot say which person is asking, so the code is put in the hidden instructions
 * and the AI hands it back with every tool call. It names one person and one conversation, and stops working after
 * `plugins.ticket_minutes`. A person only ever sees their own code.
 */
final class PluginTicket
{
    /** No look-alike characters (0, o, 1, l), so the AI copies the code exactly. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function issue(User $user, Conversation $conversation, string $requestId): string
    {
        $code = 'tk-';
        for ($i = 0; $i < 20; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        Cache::put(self::key($code), ['user_id' => $user->id, 'conversation_id' => $conversation->id, 'request_id' => $requestId], now()->addMinutes((int) config('plugins.ticket_minutes')));

        return $code;
    }

    /** @return array{user_id: int, conversation_id: int, request_id: string}|null */
    public static function resolve(string $code): ?array
    {
        if (! preg_match('/^tk-[a-z2-9]{20}$/', $code)) {
            return null;
        }
        $found = Cache::get(self::key($code));

        return is_array($found) ? $found : null;
    }

    private static function key(string $code): string
    {
        return 'plugin-ticket:'.hash('sha256', $code);
    }
}
