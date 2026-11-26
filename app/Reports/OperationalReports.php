<?php

namespace App\Reports;

use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

final class OperationalReports
{
    private function usage(ReportFilters $f): mixed
    {
        return $f->scope(DB::table('usage_records')->where('purpose', 'chat')->whereBetween('work_date', [$f->from, $f->to]));
    }

    private function attendance(ReportFilters $f): mixed
    {
        return $f->scope(DB::table('attendance_days')->whereBetween('work_date', [$f->from, $f->to]));
    }

    private const USAGE_TOTALS = "COUNT(*) AS requests, COALESCE(SUM(CASE WHEN status != 'pending' THEN input_tokens + output_tokens ELSE 0 END),0) AS tokens,
        COALESCE(SUM(CASE WHEN status != 'pending' AND is_estimate = 1 THEN input_tokens + output_tokens ELSE 0 END),0) AS estimated_tokens,
        COALESCE(SUM(status = 'pending'),0) AS pending, COALESCE(SUM(status = 'failed'),0) AS failed,
        COALESCE(SUM(status = 'success'),0) AS successful, COALESCE(SUM(status = 'cancelled'),0) AS cancelled";

    private const ATTENDANCE_TOTALS = 'COALESCE(SUM(COALESCE(active_seconds_override,computed_active_seconds)),0) AS active_seconds,
        COALESCE(SUM(computed_idle_seconds),0) AS idle_seconds, COALESCE(SUM(is_corrected),0) AS corrected_days, COUNT(*) AS attendance_days';

    private function numbers(object|array $row): array
    {
        return array_map(fn ($v) => (int) $v, (array) $row);
    }

    public function overview(ReportFilters $f): array
    {
        $totals = $this->numbers($this->usage($f)->selectRaw(self::USAGE_TOTALS)->first());
        $totals += $this->numbers($this->attendance($f)->selectRaw(self::ATTENDANCE_TOTALS)->first());
        $totals['active_users'] = $this->usage($f)->distinct()->count('user_id');
        $dailyUsage = $this->usage($f)->select('work_date')->selectRaw(self::USAGE_TOTALS)->groupBy('work_date')->get()->keyBy('work_date');
        $dailyTime = $this->attendance($f)->select('work_date')->selectRaw(self::ATTENDANCE_TOTALS)->groupBy('work_date')->get()->keyBy('work_date');
        $daily = [];
        foreach (CarbonPeriod::create($f->from, $f->to) as $date) {
            $day = $date->toDateString();
            $daily[] = ['day' => $day] + $this->numbers($dailyUsage[$day] ?? []) + $this->numbers($dailyTime[$day] ?? [])
                + ['tokens' => 0, 'estimated_tokens' => 0, 'requests' => 0, 'failed' => 0, 'successful' => 0, 'pending' => 0, 'cancelled' => 0, 'active_seconds' => 0, 'idle_seconds' => 0, 'corrected_days' => 0];
        }
        $agents = $this->usage($f)->where('status', '!=', 'pending')->select('agent_id')->selectRaw('SUM(input_tokens + output_tokens) AS tokens, COUNT(*) AS requests')
            ->groupBy('agent_id')->orderByDesc('tokens')->orderBy('agent_id')->limit(8)->get();
        $names = DB::table('agents')->whereIn('id', $agents->pluck('agent_id')->filter())->pluck('name', 'id');

        return ['totals' => $totals, 'daily' => $daily,
            'agents' => $agents->map(fn ($r) => ['id' => $r->agent_id, 'name' => $names[$r->agent_id] ?? 'Legacy assistant', 'tokens' => (int) $r->tokens, 'requests' => (int) $r->requests])->all(),
            'analysis_enabled' => false];
    }

    public function people(ReportFilters $f, int $perPage, ?int $page = null): mixed
    {
        $usage = $this->usage($f)->select('user_id')->selectRaw(self::USAGE_TOTALS)->groupBy('user_id');
        $time = $this->attendance($f)->select('user_id')->selectRaw(self::ATTENDANCE_TOTALS)->groupBy('user_id');

        return $f->scope(DB::table('users as u'), 'u.id')
            ->leftJoinSub($usage, 'usage', 'usage.user_id', '=', 'u.id')->leftJoinSub($time, 'attendance', 'attendance.user_id', '=', 'u.id')
            ->select('u.id', 'u.name', 'u.username', 'u.is_active')
            ->selectRaw('COALESCE(usage.tokens,0) AS tokens, COALESCE(usage.requests,0) AS requests, COALESCE(usage.failed,0) AS failed,
                COALESCE(attendance.active_seconds,0) AS active_seconds, COALESCE(attendance.idle_seconds,0) AS idle_seconds, COALESCE(attendance.attendance_days,0) AS attendance_days')
            ->orderByDesc('tokens')->orderBy('u.id')->paginate($perPage, ['*'], 'page', $page);
    }
}
