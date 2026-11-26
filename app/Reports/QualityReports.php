<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

final class QualityReports
{
    private function latency(ReportFilters $f, ?string $partition = null): mixed
    {
        $window = $partition ? "PARTITION BY {$partition}" : '';

        return ReportQueries::usage($f)->where('status', 'success')->whereNotNull('latency_ms')
            ->selectRaw("PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY latency_ms) OVER ({$window}) p50_ms, PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY latency_ms) OVER ({$window}) p95_ms, COUNT(*) OVER ({$window}) samples");
    }

    private function values(?object $r): array
    {
        return ['p50_ms' => $r ? (float) $r->p50_ms : null, 'p95_ms' => $r ? (float) $r->p95_ms : null, 'samples' => (int) ($r->samples ?? 0)];
    }

    public function data(ReportFilters $f): array
    {
        $ratings = ReportQueries::messages($f, 'assistant')->selectRaw("COALESCE(SUM(m.rating='up'),0) up, COALESCE(SUM(m.rating='down'),0) down, COUNT(*) answers")->first();
        $dailyLatency = $this->latency($f, 'work_date')->addSelect('work_date')->distinct()->get()->keyBy('work_date');
        $daily = app(OperationalReports::class)->overview($f)['daily'];
        foreach ($daily as &$day) {
            $day += $this->values($dailyLatency[$day['day']] ?? null);
        }
        unset($day);
        $ratingWeeks = ReportQueries::messages($f, 'assistant')->selectRaw('DATE_SUB('.ReportQueries::day().", INTERVAL WEEKDAY(CONVERT_TZ(m.created_at,'+00:00','+08:00')) DAY) week, SUM(m.rating='up') up, SUM(m.rating='down') down")
            ->groupBy('week')->orderBy('week')->get();
        $agentStats = ReportQueries::usage($f)->select('agent_id')->selectRaw("COUNT(*) requests, SUM(status='failed') failed, SUM(status='cancelled') cancelled, SUM(status='pending') pending, SUM(CASE WHEN status!='pending' THEN input_tokens ELSE 0 END) input_tokens, SUM(CASE WHEN status!='pending' THEN output_tokens ELSE 0 END) output_tokens")->groupBy('agent_id')->orderByDesc('requests')->orderBy('agent_id')->limit(100)->get();
        $agentLatency = $this->latency($f, 'agent_id')->addSelect('agent_id')->distinct()->get()->keyBy(fn ($r) => $r->agent_id ?? 0);
        $agentRatings = ReportQueries::messages($f, 'assistant')->select('c.agent_id')->selectRaw("SUM(m.rating='up') up, SUM(m.rating='down') down")->groupBy('c.agent_id')->get()->keyBy(fn ($r) => $r->agent_id ?? 0);
        $names = DB::table('agents')->whereIn('id', $agentStats->pluck('agent_id')->filter())->pluck('name', 'id');
        $depth = ReportQueries::messages($f, 'user')->select('m.conversation_id')->selectRaw('COUNT(*) questions')->groupBy('m.conversation_id');
        $buckets = [];
        foreach ([['1', 1, 1], ['2', 2, 2], ['3', 3, 3], ['4–5', 4, 5], ['6+', 6, null]] as [$name,$min,$max]) {
            $q = DB::query()->fromSub(clone $depth, 'depth')->where('questions', '>=', $min);
            if ($max) {
                $q->where('questions', '<=', $max);
            }
            $buckets[] = ['name' => $name, 'conversations' => $q->count()];
        }
        $unanswered = ReportQueries::conversations($f)->whereExists(fn ($q) => $q->selectRaw('1')->from('messages as last')
            ->whereColumn('last.conversation_id', 'c.id')->where('last.role', 'user')->whereNull('last.superseded_at')->where('last.created_at', '<', now()->subMinutes(2))
            ->whereRaw('last.id = (SELECT MAX(newest.id) FROM messages newest WHERE newest.conversation_id=c.id AND newest.superseded_at IS NULL)'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('usage_records as pending')->whereColumn('pending.conversation_id', 'c.id')->where('pending.status', 'pending')->where('pending.created_at', '>=', now()->subMinutes(15)))->count();
        $kinds = ReportQueries::usage($f)->selectRaw("SUM(request_kind='edit') edits, SUM(request_kind='regenerate') regenerations, SUM(request_kind IS NULL) unknown, SUM(status='cancelled') cancelled")->first();

        return ['latency' => $this->values($this->latency($f)->first()), 'ratings' => ['up' => (int) $ratings->up, 'down' => (int) $ratings->down, 'answers' => (int) $ratings->answers],
            'daily' => $daily, 'rating_weeks' => $ratingWeeks, 'depth' => $buckets, 'unanswered' => $unanswered,
            'attempt_types' => ['edits' => (int) $kinds->edits, 'regenerations' => (int) $kinds->regenerations, 'unknown' => (int) $kinds->unknown, 'cancelled' => (int) $kinds->cancelled],
            'agents' => $agentStats->map(fn ($r) => ['id' => $r->agent_id, 'name' => $names[$r->agent_id] ?? 'Legacy assistant', 'requests' => (int) $r->requests, 'failed' => (int) $r->failed, 'pending' => (int) $r->pending, 'cancelled' => (int) $r->cancelled, 'input_tokens' => (int) $r->input_tokens, 'output_tokens' => (int) $r->output_tokens,
                'up' => (int) ($agentRatings[$r->agent_id ?? 0]->up ?? 0), 'down' => (int) ($agentRatings[$r->agent_id ?? 0]->down ?? 0)] + $this->values($agentLatency[$r->agent_id ?? 0] ?? null))->all(),
            'telemetry' => ['first_token_ms' => null, 'tool_calls' => null, 'stop_button_events' => null],
            'notes' => ['Latency uses successful attempts with recorded duration; missing samples remain unavailable.', 'Answer ratings are employee feedback, not prompt scores.', 'Unanswered means the latest current message is from a user, older than two minutes, with no recent pending request; evaluated as of now.', 'Attempt type is tracked only for new requests after this update. Historical unknown types are not treated as zero retries.']];
    }
}
