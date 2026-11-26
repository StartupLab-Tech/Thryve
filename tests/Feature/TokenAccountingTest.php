<?php

namespace Tests\Feature;

use App\Ai\Allowance;
use App\Ai\Ledger;
use App\Ai\TokenLimitReached;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TokenGrant;
use App\Models\UsageRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    private Allowance $allowance;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->ledger = app(Ledger::class);
        $this->allowance = app(Allowance::class);
    }

    private function user(array $attrs = [], string $role = Role::EMPLOYEE): User
    {
        return User::factory()->role($role)->create($attrs);
    }

    /** Usage rows no longer belong to a provider connection; the agent id is optional in these ledger tests. */
    private function conn(): ?int
    {
        return null;
    }

    /** Open and close one attempt in a single step. */
    private function spend(User $u, int $in, int $out, string $status = 'success', $c = null, string $model = 'm1', bool $estimate = false): UsageRecord
    {
        $r = $this->ledger->begin($u, $c ?? $this->conn(), 'req-'.++$this->n, 1, $model);

        return $this->ledger->finish($r, $status, $in, $out, $estimate);
    }

    private function setLimit(User $u, int $tokens): void
    {
        $u->forceFill(['daily_token_limit' => $tokens])->save();
    }

    // ---- tokens only ---------------------------------------------------------------------------

    public function test_an_attempt_counts_tokens_and_records_no_money_cost(): void
    {
        $r = $this->spend($this->user(), 100, 50, model: 'any-model');

        $this->assertSame(150, $r->input_tokens + $r->output_tokens);
        $this->assertNull($r->cost);
        $this->assertNull($r->currency);
        $this->assertNull($r->price_snapshot);
    }

    // ---- recording attempts --------------------------------------------------------------------

    public function test_a_request_in_flight_reserves_an_estimate_and_is_replaced_by_the_real_count(): void
    {
        $u = $this->user();
        $c = $this->conn();
        $r = $this->ledger->begin($u, $c, 'req-1', 1, 'm1', estimate: 300);

        $this->assertSame('pending', $r->status);
        $this->assertTrue($r->is_estimate);
        $this->assertSame(300, $this->allowance->used($u, $r->work_date->toDateString()), 'counted while it runs');

        $done = $this->ledger->finish($r, 'success', 120, 80, latencyMs: 450);

        $this->assertSame('success', $done->status);
        $this->assertFalse($done->is_estimate, 'real numbers from the provider');
        $this->assertSame(200, $this->allowance->used($u, $r->work_date->toDateString()));
        $this->assertSame(450, $done->latency_ms);
    }

    public function test_counts_that_are_our_own_are_flagged_as_estimates(): void
    {
        $u = $this->user();
        $own = $this->spend($u, 100, 50, estimate: true);
        $kept = $this->ledger->finish($this->ledger->begin($u, $this->conn(), 'req-k', 1, 'm1', estimate: 70), 'failed');

        $this->assertTrue($own->is_estimate);
        $this->assertTrue($kept->is_estimate, 'no count arrived, so the reserved estimate is kept and flagged');
        $this->assertSame(70, $kept->input_tokens);
        $this->assertTrue($this->allowance->usesEstimates($u, $own->work_date->toDateString()));
        $this->assertTrue($this->allowance->summary($u)['includes_estimates']);
    }

    public function test_each_attempt_is_recorded_exactly_once(): void
    {
        $u = $this->user();
        $c = $this->conn();
        $this->ledger->begin($u, $c, 'req-dup', 1, 'm1');

        $this->expectException(QueryException::class);
        $this->ledger->begin($u, $c, 'req-dup', 1, 'm1');
    }

    public function test_failed_and_cancelled_attempts_count_with_their_real_tokens(): void
    {
        $u = $this->user();
        $this->spend($u, 100, 100, 'success');
        $this->spend($u, 40, 10, 'failed');
        $this->spend($u, 30, 20, 'cancelled');
        $this->spend($u, 0, 0, 'failed'); // failed before the provider did any work

        $this->assertSame(300, $this->allowance->used($u, now()->setTimezone('Asia/Manila')->toDateString()));
    }

    public function test_stale_pending_requests_are_closed_but_keep_their_reserved_tokens(): void
    {
        $u = $this->user();
        $stale = $this->ledger->begin($u, $this->conn(), 'req-old', 1, 'm1', estimate: 500);
        $fresh = $this->ledger->begin($u, $this->conn(), 'req-new', 1, 'm1', estimate: 100);
        UsageRecord::whereKey($stale->id)->update(['created_at' => now()->subMinutes(30)]);

        $this->assertSame(1, $this->ledger->reconcileStale(15));

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('interrupted', $stale->fresh()->failure);
        $this->assertSame(500, $stale->fresh()->input_tokens);
        $this->assertTrue($stale->fresh()->is_estimate);
        $this->assertSame('pending', $fresh->fresh()->status);
    }

    // ---- the allowance -------------------------------------------------------------------------

    public function test_the_limit_comes_from_the_person_else_the_default_and_extra_tokens_apply_only_that_day(): void
    {
        $u = $this->user();
        $this->assertSame(50000, $this->allowance->limit($u), 'the built-in default');

        Setting::create(['key' => 'default_daily_token_limit', 'value' => 20000]);
        $this->assertSame(20000, $this->allowance->limit($u), 'the configured default');

        $this->setLimit($u, 1000);
        $this->assertSame(1000, $this->allowance->limit($u), 'a personal limit wins');

        $admin = $this->user(role: Role::ADMIN);
        $today = now()->setTimezone('Asia/Manila')->toDateString();
        TokenGrant::create(['user_id' => $u->id, 'work_date' => $today, 'tokens' => 500, 'granted_by' => $admin->id]);
        TokenGrant::create(['user_id' => $u->id, 'work_date' => $today, 'tokens' => 250, 'granted_by' => $admin->id]);
        TokenGrant::create(['user_id' => $u->id, 'work_date' => '2020-01-01', 'tokens' => 99999, 'granted_by' => $admin->id]);

        $s = $this->allowance->summary($u);
        $this->assertSame([1000, 750, 1750], [$s['limit'], $s['extra'], $s['total']]);
    }

    public function test_remaining_and_percent_follow_usage(): void
    {
        $u = $this->user();
        $this->setLimit($u, 1000);
        $this->spend($u, 300, 150);

        $s = $this->allowance->summary($u);

        $this->assertSame([450, 550, 45], [$s['used'], $s['remaining'], $s['percent']]);
    }

    public function test_only_the_persons_own_chat_usage_counts_toward_their_allowance(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $this->setLimit($a, 1000);
        $this->spend($b, 900, 0);

        $this->assertSame(0, $this->allowance->summary($a)['used']);
    }

    public function test_usage_at_the_limit_blocks_the_next_request_and_creates_no_row(): void
    {
        $u = $this->user();
        $this->setLimit($u, 500);
        $this->spend($u, 300, 200);
        $before = UsageRecord::count();

        try {
            $this->ledger->begin($u, $this->conn(), 'req-blocked', 1, 'm1');
            $this->fail('the request should have been blocked');
        } catch (TokenLimitReached $e) {
            $this->assertSame('user', $e->scope);
            $this->assertStringContainsString('midnight', $e->getMessage());
        }
        $this->assertSame($before, UsageRecord::count());
    }

    public function test_extra_tokens_unblock_a_person_who_hit_the_limit(): void
    {
        $u = $this->user();
        $this->setLimit($u, 100);
        $this->spend($u, 100, 0);
        $this->expectException(TokenLimitReached::class);
        try {
            $this->ledger->begin($u, $this->conn(), 'r-a', 1, 'm1');
        } catch (TokenLimitReached) {
            TokenGrant::create(['user_id' => $u->id, 'work_date' => now()->setTimezone('Asia/Manila')->toDateString(), 'tokens' => 50, 'granted_by' => $this->user(role: Role::ADMIN)->id]);
            $this->assertNotNull($this->ledger->begin($u, $this->conn(), 'r-b', 1, 'm1'), 'allowed again with extra tokens');
            throw new TokenLimitReached;
        }
    }

    public function test_two_requests_in_flight_cannot_both_slip_under_the_limit(): void
    {
        $u = $this->user();
        $this->setLimit($u, 100);
        $this->ledger->begin($u, $this->conn(), 'req-1', 1, 'm1', estimate: 100); // still running

        $this->expectException(TokenLimitReached::class);
        $this->ledger->begin($u, $this->conn(), 'req-2', 1, 'm1', estimate: 100); // the first one's reservation counts
    }

    public function test_the_allowance_resets_at_philippine_midnight(): void
    {
        $u = $this->user();
        $this->setLimit($u, 1000);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 15:59:00', 'UTC')); // 23:59 in Manila
        $late = $this->spend($u, 600, 0);
        $this->assertSame('2026-11-19', $late->work_date->toDateString());
        $this->assertSame(400, $this->allowance->summary($u)['remaining']);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 16:01:00', 'UTC')); // 00:01 next day in Manila
        $s = $this->allowance->summary($u);
        $this->assertSame('2026-11-20', $s['work_date']);
        $this->assertSame([0, 1000], [$s['used'], $s['remaining']], 'a fresh allowance');
        $this->assertSame('2026-11-20 16:00:00', CarbonImmutable::parse($s['resets_at'])->format('Y-m-d H:i:s'), 'next reset is the following Manila midnight');
        $this->assertSame(400, $this->allowance->summary($u, '2026-11-19')['remaining'], 'yesterday keeps its own numbers');
    }

    // ---- warnings ------------------------------------------------------------------------------

    public function test_the_person_is_warned_once_each_at_80_90_and_100_percent(): void
    {
        $u = $this->user();
        $this->setLimit($u, 1000);

        $this->spend($u, 700, 0);
        $this->assertSame(0, Notification::count());
        $this->spend($u, 100, 0); // 80%
        $this->spend($u, 10, 0);  // still 80%
        $this->assertSame(['You have used 80% of today\'s tokens'], Notification::pluck('title')->all());
        $this->spend($u, 100, 0); // 91%
        $this->spend($u, 90, 0);  // 100%

        $this->assertSame(['You have used 80% of today\'s tokens', 'You have used 90% of today\'s tokens', 'You have used all of today\'s tokens'], Notification::orderBy('id')->pluck('title')->all());
        $this->assertSame([$u->id], Notification::pluck('user_id')->unique()->values()->all());
    }

    public function test_a_big_request_that_jumps_past_several_thresholds_sends_each_warning_once(): void
    {
        $u = $this->user();
        $this->setLimit($u, 1000);

        $this->spend($u, 1000, 0);

        $this->assertSame(3, Notification::count());
    }

    public function test_warnings_start_again_the_next_day(): void
    {
        $u = $this->user();
        $this->setLimit($u, 1000);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 02:00:00', 'UTC'));
        $this->spend($u, 800, 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 02:00:00', 'UTC'));
        $this->spend($u, 800, 0);

        $this->assertSame(2, Notification::count());
    }

    // ---- the separate system budget ------------------------------------------------------------

    public function test_background_analysis_uses_its_own_budget_and_never_touches_an_employees_allowance(): void
    {
        $this->user(role: Role::SUPER_ADMIN);
        $employee = $this->user();
        $this->setLimit($employee, 1000);
        Setting::create(['key' => 'system_daily_token_budget', 'value' => 500]);
        $c = $this->conn();

        $r = $this->ledger->begin(null, $c, 'analysis-1', 1, 'm1', 'analysis', 0);
        $this->ledger->finish($r, 'success', 400, 50);

        $this->assertSame(0, $this->allowance->summary($employee)['used'], 'not charged to the employee');
        $this->assertSame(450, $this->allowance->systemUsed($r->work_date->toDateString()));
        $this->assertSame(0, Notification::count(), 'no employee warnings for system work');

        $r2 = $this->ledger->begin(null, $c, 'analysis-2', 1, 'm1', 'analysis', 0);
        $this->ledger->finish($r2, 'success', 100, 0);
        try {
            $this->ledger->begin(null, $c, 'analysis-3', 1, 'm1', 'analysis', 0);
            $this->fail('the system budget should be used up');
        } catch (TokenLimitReached $e) {
            $this->assertSame('system', $e->scope);
        }
        $this->assertNotNull($this->ledger->begin($employee, $c, 'chat-1', 1, 'm1'), 'employees are unaffected by the system budget');
    }

    public function test_with_no_system_budget_set_analysis_is_not_limited(): void
    {
        $this->user(role: Role::SUPER_ADMIN);
        $c = $this->conn();
        $r = $this->ledger->begin(null, $c, 'a-1', 1, 'm1', 'analysis');
        $this->ledger->finish($r, 'success', 5_000_000, 0);

        $this->assertNotNull($this->ledger->begin(null, $c, 'a-2', 1, 'm1', 'analysis'));
    }
}
