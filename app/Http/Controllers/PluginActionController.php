<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Message;
use App\Models\PluginAction;
use App\Models\PluginConnection;
use App\Plugins\AsksPermission;
use App\Plugins\PluginAuthFailed;
use App\Plugins\PluginCallFailed;
use App\Plugins\PluginRegistry;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The person's decision on a change the AI proposed. Only the owner can decide, only while it is pending and not expired, and only once:
 * the row is locked while the state moves from pending to approved or denied. Approving runs the change as that person; either way
 * a short line is added to the chat so the AI knows the outcome. An app's permission question before a read is answered the same way,
 * with the answer the person picked.
 */
class PluginActionController extends Controller
{
    /** What waits for the person: a change the AI proposed, or an app's permission question before a read. */
    private const DECIDED_KINDS = ['write', 'access'];

    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $found = PluginAction::where('user_id', $user->id)->whereIn('kind', self::DECIDED_KINDS)->findOrFail($id);
        if ($found->kind === 'access') {
            return $this->allow($request, $found);
        }
        $action = $this->decide($user->id, $id, 'approved');
        if ($action === null) {
            return Api::error('not_pending', 'This change is no longer waiting for your approval.', 409);
        }

        [$status, $result] = $this->run($action);
        $summary = $result !== '' ? $result : 'Done.';
        $action->update(['status' => $status, 'executed_at' => now(), 'result_summary' => mb_substr($summary, 0, 250), 'arguments' => null, 'arguments_hash' => null]);
        Audit::log('plugin.'.$action->app_slug.'.'.($status === 'done' ? 'approved' : 'approve_failed'), $user, null, ['tool' => $action->tool, 'action_id' => $action->id, 'status' => $status]);
        $message = $this->note($action, $status === 'done' ? "{$this->label($action)} change made (you approved it): {$action->summary}. {$summary}" : "{$this->label($action)} change not made: {$action->summary}. {$summary}");

        return Api::ok(['action' => $action->fresh()->present(), 'message' => $message]);
    }

    public function deny(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $action = $this->decide($user->id, $id, 'denied');
        if ($action === null) {
            return Api::error('not_pending', 'This is no longer waiting for your answer.', 409);
        }
        $action->update(['arguments' => null, 'arguments_hash' => null]);
        Audit::log('plugin.'.$action->app_slug.'.denied', $user, null, ['tool' => $action->tool, 'action_id' => $action->id]);
        $message = $this->note($action, $action->kind === 'access'
            ? "{$this->label($action)} was not allowed to show it (you said no)."
            : "{$this->label($action)} change not made (you denied it): {$action->summary}.");

        return Api::ok(['action' => $action->fresh()->present(), 'message' => $message]);
    }

    /** Moves a pending change to its new state under a row lock; null when it is not pending, or ran out of time. */
    private function decide(int $userId, int $id, string $to): ?PluginAction
    {
        return DB::transaction(function () use ($userId, $id, $to) {
            $action = PluginAction::where('user_id', $userId)->whereIn('kind', self::DECIDED_KINDS)->lockForUpdate()->findOrFail($id);
            if ($action->status !== 'pending') {
                return null;
            }
            if ($action->expires_at->isPast()) {
                $action->update(['status' => 'expired', 'arguments' => null, 'arguments_hash' => null]);

                return null;
            }
            $action->update(['status' => $to, 'decided_at' => now()]);

            return $action;
        });
    }

    /**
     * The person answers an app's permission question with one of the answers it offered. The answer goes back to the app with the same
     * call; what the app then returns is kept (encrypted, for a few minutes) for the AI, and the chat asks the AI again. Nothing is added
     * to the chat on success, so the retried answer replaces the "waiting" one.
     */
    private function allow(Request $request, PluginAction $found): JsonResponse
    {
        if ($found->status !== 'pending') {
            return Api::error('not_pending', 'This is no longer waiting for your answer.', 409);
        }
        $choice = (string) $request->input('choice', '');
        if (! in_array($choice, array_column($found->permissionChoices(), 'value'), true)) {
            return Api::error('validation_failed', 'Choose one of the answers on the card.', 422);
        }
        $stored = (array) $found->arguments;
        $action = $this->decide($found->user_id, $found->id, 'approved');
        if ($action === null) {
            return Api::error('not_pending', 'This is no longer waiting for your answer.', 409);
        }

        $label = $this->label($action);
        $provider = PluginRegistry::find($action->app_slug);
        $connection = PluginConnection::active()->where('user_id', $action->user_id)->where('app_slug', $action->app_slug)->first();
        $result = null;
        $failure = null;
        if (! $provider instanceof AsksPermission || ! $connection) {
            $failure = "{$label} is not connected any more. Connect it again on the Plugins page.";
        } else {
            try {
                $result = $provider->answerPermission($connection, $action->tool, (array) ($stored['arguments'] ?? []), (array) ($stored['permission'] ?? []), $choice);
                $connection->update(['last_used_at' => now()]);
            } catch (PluginAuthFailed) {
                $connection->update(['status' => 'needs_reconnect']);
                $failure = "{$label} refused your login. Connect it again on the Plugins page.";
            } catch (PluginCallFailed $e) {
                $failure = $e->getMessage();
            }
        }
        if ($result !== null && isset($result['permission'])) {
            $failure = "{$label} asked again. Send your question again to see the new request.";
        } elseif ($result !== null && $result['is_error']) {
            $failure = "{$label} reported an error: ".mb_substr(trim((string) preg_replace('/\s+/', ' ', $result['text'])), 0, 150);
        }

        $chosen = collect($found->permissionChoices())->firstWhere('value', $choice)['label'] ?? $choice;
        if ($failure !== null) {
            $action->update(['status' => 'failed', 'executed_at' => now(), 'result_summary' => mb_substr($failure, 0, 250), 'arguments' => null, 'arguments_hash' => null]);
            Audit::log('plugin.'.$action->app_slug.'.permission_failed', $request->user(), null, ['tool' => $action->tool, 'action_id' => $action->id, 'choice' => $choice]);

            return Api::ok(['action' => $action->fresh()->present(), 'message' => $this->note($action, "{$label} could not show it: {$failure}"), 'retry' => false]);
        }
        $cap = (int) config('plugins.result_cap');
        $text = mb_strlen($result['text']) > $cap ? mb_substr($result['text'], 0, $cap)."\n[Shortened: the result was longer than {$cap} characters.]" : $result['text'];
        $action->update(['status' => 'done', 'executed_at' => now(), 'result_summary' => mb_substr("You answered: {$chosen}", 0, 250), 'arguments' => ['result' => $text]]);
        Audit::log('plugin.'.$action->app_slug.'.permission_given', $request->user(), null, ['tool' => $action->tool, 'action_id' => $action->id, 'choice' => $choice]);

        return Api::ok(['action' => $action->fresh()->present(), 'message' => null, 'retry' => true]);
    }

    /** @return array{0: string, 1: string} the final status and a one-line result */
    private function run(PluginAction $action): array
    {
        $provider = PluginRegistry::find($action->app_slug);
        $label = $this->label($action);
        $connection = PluginConnection::active()->where('user_id', $action->user_id)->where('app_slug', $action->app_slug)->first();
        if (! $provider || ! $connection) {
            return ['failed', "{$label} is not connected any more. Connect it again on the Plugins page."];
        }
        try {
            $result = $provider->callTool($connection, $action->tool, (array) $action->arguments, false);
        } catch (PluginAuthFailed) {
            $connection->update(['status' => 'needs_reconnect']);

            return ['failed', "{$label} refused your login. Connect it again on the Plugins page."];
        } catch (PluginCallFailed $e) {
            return ['failed', $e->getMessage()];
        }
        $connection->update(['last_used_at' => now()]);
        $line = trim((string) preg_replace('/\s+/', ' ', $result['text']));

        return $result['is_error'] ? ['failed', "{$label} reported an error: ".mb_substr($line, 0, 150)] : ['done', mb_substr($line, 0, 200)];
    }

    private function label(PluginAction $action): string
    {
        return PluginRegistry::find($action->app_slug)?->name() ?? 'The app';
    }

    /** @return array<string, mixed>|null the chat line that was added, in the shape the chat page uses */
    private function note(PluginAction $action, string $text): ?array
    {
        if (! $action->conversation) {
            return null;
        }
        $message = Message::create(['conversation_id' => $action->conversation_id, 'role' => 'assistant', 'content' => $text]);
        $action->conversation->update(['last_message_at' => now()]);

        return ['id' => $message->id, 'role' => 'assistant', 'content' => $text, 'rating' => null, 'completion_status' => null, 'created_at' => $message->created_at->toIso8601String(),
            'model' => null, 'model_label' => null, 'attachments' => []];
    }
}
