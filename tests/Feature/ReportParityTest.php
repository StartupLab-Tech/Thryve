<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Attachment;
use App\Models\AttendanceDay;
use App\Models\AuditEntry;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Support\Audit;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class ReportParityTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-22 04:00:00 UTC'));
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        Storage::fake('private');
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $cookie = $this->cookieFor(Role::ADMIN);
        $user = $this->makeUser();
        $agent = Agent::create(['name' => 'Sample assistant', 'slug' => 'sample', 'base_url' => 'https://example.test', 'is_enabled' => true, 'is_default' => true, 'outbound_key' => 'synthetic-key']);
        $c = Conversation::create(['user_id' => $user->id, 'agent_id' => $agent->id, 'title' => '=HYPERLINK("sample")', 'last_message_at' => '2026-11-22 03:00:00']);
        Message::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'Summarize the quarterly invoice spreadsheet.', 'created_at' => '2026-11-22 03:00:00']);

        return [$cookie, $user, $agent, $c];
    }

    private function attempt($user, $agent, $c, array $extra = []): void
    {
        $id = Str::uuid()->toString();
        UsageRecord::create($extra + ['attempt_id' => $id.'#1', 'request_id' => $id, 'user_id' => $user->id, 'agent_id' => $agent->id, 'conversation_id' => $c->id,
            'model' => 'sample', 'purpose' => 'chat', 'status' => 'success', 'input_tokens' => 100, 'output_tokens' => 50, 'work_date' => '2026-11-22',
            'is_estimate' => false, 'cost' => 0.02, 'currency' => 'USD', 'latency_ms' => 1000, 'created_at' => '2026-11-22 03:00:00']);
    }

    public function test_quality_uses_real_latency_ratings_and_explicitly_missing_telemetry(): void
    {
        [$cookie, $user, $agent, $c] = $this->fixture();
        $this->attempt($user, $agent, $c);
        $this->attempt($user, $agent, $c, ['latency_ms' => 3000]);
        $this->attempt($user, $agent, $c, ['status' => 'failed', 'latency_ms' => 9000, 'cost' => null]);
        Message::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'A summary.', 'rating' => 'up', 'created_at' => '2026-11-22 03:01:00']);
        $r = $this->device($cookie)->getJson('/api/reports/quality?user_id='.$user->id)->assertOk();
        $r->assertJsonPath('data.latency.p50_ms', 2000)->assertJsonPath('data.latency.p95_ms', 2900)->assertJsonPath('data.latency.samples', 2)
            ->assertJsonPath('data.ratings.up', 1)->assertJsonPath('data.telemetry.first_token_ms', null)->assertJsonPath('data.telemetry.tool_calls', null);
        Http::assertNothingSent();
    }

    public function test_usage_details_preserve_currency_and_department_overlap(): void
    {
        [$cookie, $user, $agent, $c] = $this->fixture();
        $a = Department::create(['name' => 'Operations']);
        $b = Department::create(['name' => 'Finance']);
        $user->departments()->attach([$a->id, $b->id]);
        $this->attempt($user, $agent, $c);
        $this->attempt($user, $agent, $c, ['currency' => 'PHP', 'cost' => 1]);
        $this->attempt($user, $agent, $c, ['purpose' => 'analysis', 'cost' => 99]);
        $r = $this->device($cookie)->getJson('/api/reports/usage?user_id='.$user->id)->assertOk();
        $r->assertJsonPath('data.totals.input_tokens', 200)->assertJsonPath('data.totals.output_tokens', 100)->assertJsonCount(4, 'data.departments')->assertJsonCount(2, 'data.month');
        $this->assertSame(1, (int) collect($r->json('data.buckets'))->sum('conversations'));
        $this->device($cookie)->getJson('/api/reports/usage?department_id='.$a->id)->assertOk()->assertJsonCount(2, 'data.departments');
    }

    public function test_adoption_counts_messages_on_philippine_dates_and_current_access(): void
    {
        [$cookie, $user, $agent, $c] = $this->fixture();
        Message::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'Invoice review', 'created_at' => '2026-11-21 16:05:00']);
        $r = $this->device($cookie)->getJson('/api/reports/adoption?user_id='.$user->id)->assertOk();
        $r->assertJsonPath('data.active.end_date', 1)->assertJsonPath('data.active.last_7_days', 1)->assertJsonPath('data.seats.allowed', 1)->assertJsonPath('data.seats.unused', 0)
            ->assertJsonPath('data.matrix.rows.0.cells.0.questions', 2)->assertJsonPath('data.matrix.rows.0.cells.0.allowed', true);
        $this->assertEquals(2, collect($r->json('data.heat'))->sum(fn ($row) => array_sum($row)));
        $r->assertJsonCount(7, 'data.daily')->assertJsonPath('data.daily.0.people', 0)->assertJsonPath('data.daily.6.people', 1);
    }

    public function test_retained_conversation_inspection_and_download_are_audited_and_consistent(): void
    {
        [$cookie, $user, $agent, $c] = $this->fixture();
        $c->update(['deleted_at' => now()]);
        $m = Message::where('conversation_id', $c->id)->first();
        Storage::disk('private')->put('attachments/sample.bin', 'synthetic bytes');
        $a = Attachment::create(['user_id' => $user->id, 'conversation_id' => $c->id, 'message_id' => $m->id, 'original_name' => 'sample.txt', 'kind' => 'txt', 'size' => 15, 'sha256' => hash('sha256', 'synthetic bytes'), 'storage_key' => 'attachments/sample.bin', 'extracted_text' => 'synthetic bytes']);
        $this->device($cookie)->getJson('/api/reports/conversations?state=deleted')->assertOk()->assertJsonPath('data.0.id', $c->id);
        $this->device($cookie)->getJson('/api/reports/conversations/'.$c->id)->assertOk()->assertJsonPath('data.messages.0.content', $m->content)->assertJsonMissingPath('data.messages.0.attachments.0.storage_key');
        $this->device($cookie)->get('/api/reports/conversations/'.$c->id.'/attachments/'.$a->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = Conversation::create(['user_id' => $user->id, 'title' => 'Other']);
        $this->device($cookie)->getJson('/api/reports/conversations/'.$other->id.'/attachments/'.$a->id)->assertNotFound();
        $this->assertDatabaseHas('audit_entries', ['action' => 'reports.conversation.inspected', 'target_id' => $c->id]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'reports.file.downloaded', 'target_id' => $a->id]);
    }

    public function test_new_reports_and_exports_deny_employee_access_and_audit_is_read_only(): void
    {
        $cookie = $this->cookieFor(Role::EMPLOYEE);
        foreach (['usage', 'quality', 'adoption', 'conversations', 'audit', 'export?report=people&format=csv'] as $path) {
            $this->device($cookie)->getJson('/api/reports/'.$path)->assertForbidden();
        }
        $cookie = $this->cookieFor(Role::ADMIN);
        $this->device($cookie)->getJson('/api/reports/audit')->assertOk();
        $this->device($cookie)->deleteJson('/api/reports/audit/1')->assertNotFound();
    }

    public function test_exports_are_real_files_safe_as_data_and_limited_without_silent_truncation(): void
    {
        [$cookie, $user, $agent, $c] = $this->fixture();
        $this->attempt($user, $agent, $c);
        $csv = $this->device($cookie)->get('/api/reports/export?report=conversations&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        foreach (['xlsx' => 'PK', 'pdf' => '%PDF-'] as $format => $prefix) {
            $bytes = $this->device($cookie)->get('/api/reports/export?report=usage&format='.$format)->assertOk()->getContent();
            $this->assertStringStartsWith($prefix, $bytes);
        }
        config(['reports.export_limit' => 1]);
        $this->attempt($user, $agent, $c);
        $this->device($cookie)->getJson('/api/reports/export?report=attempts&format=csv')->assertUnprocessable();
        $this->assertDatabaseHas('audit_entries', ['action' => 'reports.exported']);
    }

    public function test_exports_ignore_visible_page_and_preserve_filters_and_xlsx_text(): void
    {
        [$cookie, $user, $agent, $c] = $this->fixture();
        $this->attempt($user, $agent, $c);
        $other = $this->makeUser(Role::EMPLOYEE, ['name' => 'Other account']);
        $this->attempt($other, $agent, $c);
        $csv = $this->device($cookie)->get('/api/reports/export?report=people&format=csv&page=9&user_id='.$user->id)->assertOk()->getContent();
        $this->assertStringContainsString($user->username, $csv);
        $this->assertStringNotContainsString($other->username, $csv);
        $bytes = $this->device($cookie)->get('/api/reports/export?report=conversations&format=xlsx')->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $cells = $book->getActiveSheet()->toArray();
            $found = false;
            foreach ($cells as $row) {
                foreach ($row as $value) {
                    if ($value === $c->title) {
                        $found = true;
                    }
                }
            }
            $this->assertTrue($found);
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_super_admin_can_inspect_history_and_audit_metadata_is_scrubbed(): void
    {
        [, $user, $agent, $c] = $this->fixture();
        $cookie = $this->cookieFor(Role::SUPER_ADMIN);
        Message::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'Replaced answer', 'superseded_at' => now()]);
        $this->device($cookie)->getJson('/api/reports/conversations/'.$c->id)->assertOk()->assertJsonCount(1, 'data.messages');
        $this->device($cookie)->getJson('/api/reports/conversations/'.$c->id.'?history=1')->assertOk()->assertJsonCount(2, 'data.messages');
        Audit::log('fixture.test', metadata: ['api_key' => 'synthetic-secret', 'safe' => 'visible']);
        $r = $this->device($cookie)->getJson('/api/reports/audit?action=fixture.test&target_type=missing')->assertOk()->assertJsonCount(0, 'data');
        $r = $this->device($cookie)->getJson('/api/reports/audit?action=fixture.test')->assertOk();
        $r->assertJsonPath('data.0.metadata.safe', 'visible')->assertJsonMissingPath('data.0.metadata.api_key');
        $this->assertStringNotContainsString('synthetic-secret', $r->getContent());
        foreach (['usage', 'quality', 'adoption'] as $report) {
            $this->device($cookie)->getJson('/api/reports/'.$report)->assertOk();
        }
    }

    public function test_attendance_details_and_export_use_official_corrected_times_without_reconciliation(): void
    {
        [$cookie,$user] = $this->fixture();
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-11-22', 'computed_active_seconds' => 100, 'active_seconds_override' => 250, 'computed_idle_seconds' => 30, 'computed_first_in_at' => '2026-11-22 01:00:00', 'first_in_override' => '2026-11-22 02:00:00', 'is_corrected' => true]);
        $this->device($cookie)->getJson('/api/reports/attendance?user_id='.$user->id)->assertOk()->assertJsonPath('data.0.active_seconds', 250)->assertJsonPath('data.0.idle_seconds', 30)->assertJsonPath('data.0.first_in_at', '2026-11-22T02:00:00+00:00');
        $csv = $this->device($cookie)->get('/api/reports/export?report=attendance&format=csv&user_id='.$user->id)->assertOk()->getContent();
        $this->assertStringContainsString('first_in_at (PHT)', $csv);
        $this->assertStringContainsString('2026-11-22 10:00:00', $csv); // 02:00 UTC is 10:00 Philippine time
        $this->assertStringNotContainsString('2026-11-22T02:00:00+00:00', $csv);
        $this->assertSame($day->getRawOriginal('updated_at'), $day->fresh()->getRawOriginal('updated_at'));
    }

    public function test_exports_show_times_in_philippine_time_with_the_zone_in_the_heading(): void
    {
        [$cookie, $user] = $this->fixture();
        $entry = AuditEntry::create(['actor_id' => $user->id, 'actor_username' => $user->username, 'action' => 'test.time_check', 'ip_address' => '127.0.0.1']);
        DB::table('audit_entries')->where('id', $entry->id)->update(['created_at' => '2026-11-22 03:00:00']);

        $audit = $this->device($cookie)->get('/api/reports/export?report=audit&format=csv')->assertOk()->getContent();
        $line = collect(explode("\n", $audit))->first(fn ($l) => str_contains($l, 'test.time_check'));
        $this->assertStringContainsString('at (PHT)', $audit);
        $this->assertStringContainsString('2026-11-22 11:00:00', $line); // 03:00 UTC is 11:00 Philippine time
        $this->assertStringNotContainsString('03:00:00', $line);
        $this->assertStringContainsString('+08:00', $audit); // "generated_at" carries the Philippine offset

        $conversations = $this->device($cookie)->get('/api/reports/export?report=conversations&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('last_message_at (PHT)', $conversations);
        $this->assertStringContainsString('2026-11-22 11:00:00', $conversations);
    }

    public function test_business_time_text_reads_stored_values_as_utc(): void
    {
        $this->assertSame('2026-11-22 11:00:00', BusinessDay::stamp('2026-11-22 03:00:00'));
        $this->assertSame('2026-11-22 10:00:00', BusinessDay::stamp('2026-11-22T02:00:00+00:00'));
        $this->assertSame('2026-11-23 00:30:00', BusinessDay::stamp('2026-11-22 16:30:00')); // crosses midnight
        $this->assertNull(BusinessDay::stamp(null));
        $this->assertSame('PHT', BusinessDay::label());
    }
}
