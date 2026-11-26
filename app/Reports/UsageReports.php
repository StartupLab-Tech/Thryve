<?php

namespace App\Reports;

use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

final class UsageReports
{
    public function data(ReportFilters $f): array
    {
        $base = ReportQueries::usage($f)->where('status', '!=', 'pending');
        $tokens = (clone $base)->selectRaw('COALESCE(SUM(input_tokens),0) input_tokens, COALESCE(SUM(output_tokens),0) output_tokens, COUNT(*) attempts, COALESCE(SUM(is_estimate=1),0) estimated_attempts')->first();
        $byDay = (clone $base)->select('work_date')->selectRaw('SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens')->groupBy('work_date')->get()->keyBy('work_date');
        $daily = [];
        $recent = [];
        foreach (CarbonPeriod::create($f->from, $f->to) as $date) {
            $r = $byDay[$date->toDateString()] ?? null;
            $in = (int) ($r->input_tokens ?? 0);
            $out = (int) ($r->output_tokens ?? 0);
            $recent[] = $in + $out;
            if (count($recent) > 7) {
                array_shift($recent);
            }
            $daily[] = ['day' => $date->toDateString(), 'input_tokens' => $in, 'output_tokens' => $out, 'tokens' => $in + $out, 'moving_average' => round(array_sum($recent) / count($recent), 2)];
        }
        $departments = (clone $base)->join('department_user as du', 'du.user_id', '=', 'usage_records.user_id')->join('departments as d', 'd.id', '=', 'du.department_id')
            ->when($f->departmentId, fn ($q) => $q->where('d.id', $f->departmentId))
            ->select('d.id', 'd.name')->selectRaw('SUM(input_tokens+output_tokens) tokens')->groupBy('d.id', 'd.name')->orderBy('d.name')->get();
        $heaviestUsers = (clone $base)->join('users as u', 'u.id', '=', 'usage_records.user_id')
            ->select('u.id', 'u.name')->selectRaw('SUM(input_tokens+output_tokens) tokens')->groupBy('u.id', 'u.name')->orderByDesc('tokens')->limit(30)->get();
        $conversationUsage = (clone $base)->whereNotNull('conversation_id')->select('conversation_id')->selectRaw('SUM(input_tokens+output_tokens) tokens')->groupBy('conversation_id');
        $buckets = [];
        foreach ([['Under 1k', 0, 1000], ['1–2k', 1000, 2000], ['2–5k', 2000, 5000], ['5–10k', 5000, 10000], ['10–20k', 10000, 20000], ['20k+', 20000, null]] as [$name, $min, $max]) {
            $q = DB::query()->fromSub(clone $conversationUsage, 'cu')->where('tokens', '>=', $min);
            if ($max !== null) {
                $q->where('tokens', '<', $max);
            }
            $buckets[] = ['name' => $name, 'conversations' => $q->count()];
        }
        $top = (clone $base)->join('conversations as c', 'c.id', '=', 'usage_records.conversation_id')->join('users as u', 'u.id', '=', 'usage_records.user_id')
            ->select('c.id', 'c.title', 'u.name')->selectRaw('SUM(input_tokens+output_tokens) tokens')->groupBy('c.id', 'c.title', 'u.name')->orderByDesc('tokens')->limit(20)->get();

        return ['totals' => ['input_tokens' => (int) $tokens->input_tokens, 'output_tokens' => (int) $tokens->output_tokens, 'attempts' => (int) $tokens->attempts,
            'tokens_per_attempt' => $tokens->attempts ? round(($tokens->input_tokens + $tokens->output_tokens) / $tokens->attempts, 2) : null],
            'coverage' => ['estimated_attempts' => (int) $tokens->estimated_attempts],
            'daily' => $daily, 'departments' => $departments, 'heaviest_users' => $heaviestUsers, 'buckets' => $buckets, 'top_conversations' => $top,
            'notes' => ['Department subtotals overlap for people in multiple departments. Unassigned people are not in department groups.', 'Heaviest-user and conversation lists show up to 30 and 20 rows respectively.']];
    }
}
