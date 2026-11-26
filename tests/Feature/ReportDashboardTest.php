<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Department;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class ReportDashboardTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-22 04:00:00 UTC'));
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    private function record(User $user, array $values = []): void
    {
        $id = Str::uuid()->toString();
        UsageRecord::create($values + ['attempt_id' => $id.'#1', 'request_id' => $id, 'user_id' => $user->id,
            'provider_connection_id' => null, 'model' => 'sample', 'purpose' => 'chat', 'status' => 'success',
            'input_tokens' => 100, 'output_tokens' => 50, 'is_estimate' => false, 'work_date' => '2026-11-22',
            'latency_ms' => 1000, 'created_at' => '2026-11-22 03:00:00']);
    }

    public function test_all_report_endpoints_deny_guests_and_employees(): void
    {
        $paths = ['/reports/overview', '/reports/people', '/reports/filters'];
        foreach ($paths as $path) {
            $this->device(null)->getJson('/api'.$path)->assertUnauthorized();
        }
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        foreach ($paths as $path) {
            $this->device($cookie)->getJson('/api'.$path)->assertForbidden();
        }
    }

    public function test_both_admin_roles_can_read_reports_without_ai_calls(): void
    {
        foreach ([Role::ADMIN, Role::SUPER_ADMIN] as $role) {
            $cookie = $this->cookieFor($role);
            $this->device($cookie)->getJson('/api/reports/overview')->assertOk()->assertJsonPath('meta.timezone', 'Asia/Manila')->assertJsonCount(7, 'data.daily');
        }
        Http::assertNothingSent();
    }

    public function test_aggregate_reconciles_estimates_pending_system_and_currencies_without_membership_duplicates(): void
    {
        $cookie = $this->cookieFor(Role::ADMIN);
        $employee = $this->makeUser();
        $one = Department::create(['name' => 'Operations']);
        $two = Department::create(['name' => 'Finance']);
        $employee->departments()->attach([$one->id, $two->id]);
        $this->record($employee);
        $this->record($employee, ['status' => 'failed', 'input_tokens' => 20, 'output_tokens' => 0, 'is_estimate' => true]);
        $this->record($employee, ['status' => 'pending', 'input_tokens' => 900, 'output_tokens' => 0]);
        $this->record($employee, ['purpose' => 'analysis', 'input_tokens' => 500]);
        $this->record($employee);
        AttendanceDay::create(['user_id' => $employee->id, 'work_date' => '2026-11-22', 'computed_active_seconds' => 100, 'active_seconds_override' => 3600, 'computed_idle_seconds' => 300, 'is_corrected' => true]);
        $url = '/api/reports/overview?from=2026-11-22&to=2026-11-22&department_id='.$one->id;
        $r = $this->device($cookie)->getJson($url)->assertOk();
        $r->assertJsonPath('data.totals.tokens', 320)->assertJsonPath('data.totals.estimated_tokens', 20)->assertJsonPath('data.totals.pending', 1)
            ->assertJsonPath('data.totals.failed', 1)->assertJsonPath('data.totals.active_users', 1)->assertJsonPath('data.totals.active_seconds', 3600)
            ->assertJsonPath('data.totals.idle_seconds', 300)->assertJsonMissingPath('data.costs')->assertJsonMissingPath('data.totals.unpriced');
        $r->assertJsonPath('data.daily.0.tokens', 320);
        $other = $this->makeUser();
        $this->device($cookie)->getJson($url.'&user_id='.$other->id)->assertOk()->assertJsonPath('data.totals.tokens', 0);
    }

    public function test_dates_are_validated_and_philippine_bounds_are_explicit(): void
    {
        $cookie = $this->cookieFor(Role::ADMIN);
        foreach (['from=2026-11-22&to=2026-11-21', 'from=2025-01-01&to=2026-11-22', 'to=2026-11-23', 'from=oops', 'days=8'] as $query) {
            $this->device($cookie)->getJson('/api/reports/overview?'.$query)->assertUnprocessable();
        }
        $this->device($cookie)->getJson('/api/reports/overview?from=2024-02-29&to=2024-02-29')->assertOk()
            ->assertJsonPath('meta.start_utc', '2024-02-28T16:00:00+00:00')->assertJsonPath('meta.end_utc', '2024-02-29T16:00:00+00:00');
    }

    public function test_people_and_filter_search_are_paginated_and_do_not_expose_secrets(): void
    {
        $cookie = $this->cookieFor(Role::ADMIN);
        User::factory()->role(Role::EMPLOYEE)->count(27)->create();
        $this->device($cookie)->getJson('/api/reports/filters?q=&page=2&per_page=10')->assertOk()->assertJsonCount(10, 'data.users')->assertJsonPath('meta.total', 28)->assertJsonMissingPath('data.users.0.password');
        $this->device($cookie)->getJson('/api/reports/people?per_page=10')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 28);
        $this->assertDatabaseHas('audit_entries', ['action' => 'reports.people.viewed']);
    }

    public function test_missing_attendance_is_distinguishable_from_recorded_zero_and_token_estimates_are_labeled(): void
    {
        $cookie = $this->cookieFor(Role::ADMIN);
        $user = $this->makeUser();
        $this->record($user, ['is_estimate' => true]);
        $query = '?user_id='.$user->id;
        $this->device($cookie)->getJson('/api/reports/people'.$query)->assertOk()->assertJsonPath('data.0.attendance_days', 0);
        $this->device($cookie)->getJson('/api/reports/overview'.$query)->assertOk()->assertJsonPath('data.totals.estimated_tokens', 150);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-11-22']);
        $this->device($cookie)->getJson('/api/reports/people'.$query)->assertOk()->assertJsonPath('data.0.attendance_days', 1)->assertJsonPath('data.0.active_seconds', 0);
    }
}
