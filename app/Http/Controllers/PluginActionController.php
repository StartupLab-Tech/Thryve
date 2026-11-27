<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Message;
use App\Models\PluginAction;
use App\Models\PluginConnection;
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
 * a short line is added to the chat so the AI knows the outcome.
 */
class PluginActionController extends Controller
{
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
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
            return Api::error('not_pending', 'This change is no longer waiting for your approval.', 409);
        }
        $action->update(['arguments' => null, 'arguments_hash' => null]);
        Audit::log('plugin.'.$action->app_slug.'.denied', $user, null, ['tool' => $action->tool, 'action_id' => $action->id]);
        $message = $this->note($action, "{$this->label($action)} change not made (you denied it): {$action->summary}.");

        return Api::ok(['action' => $action->fresh()->present(), 'message' => $message]);
    }

    /** Moves a pending change to its new state under a row lock; null when it is not pending, or ran out of time. */
    private function decide(int $userId, int $id, string $to): ?PluginAction
    {
        return DB::transaction(function () use ($userId, $id, $to) {
            $action = PluginAction::where('user_id', $userId)->where('kind', 'write')->lockForUpdate()->findOrFail($id);
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
