<?php

namespace App\Ai;

use App\Models\UsageRecord;
use App\Models\User;
use App\Support\BusinessDay;

/**
 * The usage ledger: one row per chargeable attempt. begin() reserves an estimate; finish() replaces it with the
 * provider's real numbers (or keeps the estimate, flagged, when real numbers are not available).
 */
class Ledger
{
    public function __construct(private Allowance $allowance) {}

    /** Reserve tokens and open the record. @throws TokenLimitReached */
    public function begin(?User $user, ?int $agentId, string $requestId, int $attempt, string $model, string $purpose = 'chat', int $estimate = 0, ?int $conversationId = null, ?string $requestKind = null): UsageRecord
    {
        $date = BusinessDay::today();
        $lockKey = $purpose === 'analysis' ? 'system' : 'user:'.$user->id;

        return $this->allowance->withLock($lockKey, function () use ($user, $agentId, $requestId, $attempt, $model, $purpose, $estimate, $conversationId, $date, $requestKind) {
            $this->allowance->assertAvailable($user, $purpose, $date);

            return UsageRecord::create([
                'attempt_id' => "{$requestId}#{$attempt}", 'request_id' => $requestId, 'user_id' => $user?->id ?? $this->systemUserId(),
                'conversation_id' => $conversationId, 'agent_id' => $agentId, 'model' => $model, 'purpose' => $purpose, 'request_kind' => $requestKind,
                'input_tokens' => max(0, $estimate), 'output_tokens' => 0, 'is_estimate' => true, 'status' => 'pending', 'work_date' => $date,
            ]);
        });
    }

    private function systemUserId(): int
    {
        // Background analysis is not caused by one person; it is recorded against the first Super Admin.
        return (int) User::whereHas('role', fn ($r) => $r->where('slug', 'super_admin'))->orderBy('id')->value('id');
    }

    /**
     * Close a record. Pass real counts from the provider when you have them. With $input and $output both null the
     * reserved estimate is kept and marked as an estimate (for example when a request may have been charged but the
     * count never arrived). A failure before the provider did any work passes 0 and 0.
     */
    public function finish(UsageRecord $r, string $status, ?int $input = null, ?int $output = null, bool $isEstimate = false, ?int $latencyMs = null, ?string $failure = null): UsageRecord
    {
        $keepEstimate = $input === null && $output === null;
        $in = $keepEstimate ? $r->input_tokens : (int) $input;
        $out = $keepEstimate ? $r->output_tokens : (int) $output;

        $r->update([
            'status' => $status, 'failure' => $failure, 'input_tokens' => $in, 'output_tokens' => $out,
            'is_estimate' => $keepEstimate ? true : $isEstimate, 'latency_ms' => $latencyMs,
        ]);

        if ($r->purpose === 'chat') {
            $this->allowance->notifyThresholds($r->user, $r->work_date->toDateString());
        }

        return $r->fresh();
    }

    /** Requests that never finished (a crash, a dropped connection) keep their estimate and are closed as failed. */
    public function reconcileStale(int $minutes = 15): int
    {
        $n = 0;
        UsageRecord::where('status', 'pending')->where('created_at', '<', now()->subMinutes($minutes))->each(function ($r) use (&$n) {
            $this->finish($r, 'failed', failure: 'interrupted');
            $n++;
        });

        return $n;
    }
}
