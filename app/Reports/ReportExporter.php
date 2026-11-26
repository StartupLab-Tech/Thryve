<?php

namespace App\Reports;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportExporter
{
    public function build(string $report, string $format, ReportFilters $f, Request $request): array
    {
        $limit = min((int) config('reports.export_limit', 5000), $format === 'pdf' ? 500 : 5000);
        $inspection = app(InspectionReports::class);
        $rows = match ($report) {
            'attempts' => ReportQueries::usage($f)->orderBy('id')->limit($limit + 1)->get(['id', 'user_id', 'agent_id', 'conversation_id', 'work_date', 'model', 'status', 'request_kind', 'input_tokens', 'output_tokens', 'is_estimate', 'cost', 'currency', 'latency_ms'])->map(fn ($r) => (array) $r)->all(),
            'conversations' => $inspection->conversations($f, $request)->limit($limit + 1)->get()->map(fn ($r) => (array) $r)->all(),
            'audit' => $inspection->audit($f, $request)->limit($limit + 1)->get()->map(fn ($r) => $inspection->presentAudit($r))->all(),
            'people' => $this->people($f, $limit),
            'attendance' => app(AttendanceReports::class)->query($f)->limit($limit + 1)->get()->map(fn ($r) => app(AttendanceReports::class)->present($r))->all(),
            'feedback' => ReportQueries::messages($f, 'assistant')->whereNotNull('m.rating')->orderBy('m.id')->limit($limit + 1)->select('m.id', 'm.conversation_id', 'c.user_id', 'c.agent_id', 'm.rating', 'm.created_at')->selectRaw("LEFT(m.content,1000) answer_excerpt, (SELECT LEFT(q.content,1000) FROM messages q WHERE q.conversation_id=m.conversation_id AND q.role='user' AND q.superseded_at IS NULL AND q.id<m.id ORDER BY q.id DESC LIMIT 1) question_excerpt")->get()->map(fn ($r) => (array) $r)->all(),
            default => $this->summary($report, $f),
        };
        if (count($rows) > $limit) {
            throw ValidationException::withMessages(['report' => "This export exceeds {$limit} rows. Narrow the dates or select a person or department."]);
        }
        $rows = $this->inBusinessTime($rows);
        $metadata = ['report' => $report, 'from' => $f->from, 'to' => $f->to, 'timezone' => BusinessDay::timezone(), 'times' => 'Time columns are marked ('.BusinessDay::label().') and shown in '.BusinessDay::timezone().' time (UTC+8).', 'generated_at' => CarbonImmutable::now(BusinessDay::timezone())->toIso8601String(), 'user_id' => $f->userId ?? 'All', 'department_id' => $f->departmentId ?? 'All', 'definition_version' => 1, 'membership' => 'Current membership; historical access and allowances are not reconstructed.', 'sources' => 'Recorded chat only; estimates flagged; missing costs and telemetry are not zero. Summary lists retain their stated top-list and sample bounds.'];
        $headers = array_values(array_unique(array_merge(...array_map('array_keys', $rows ?: [['result' => '']]))));
        $table = array_map(fn ($r) => array_map(fn ($h) => $this->cell($r[$h] ?? null), $headers), $rows);
        $stream = fopen('php://temp', 'w+');
        try {
            if ($format === 'csv') {
                fwrite($stream, "\xEF\xBB\xBF");
                foreach ($metadata as $key => $value) {
                    fputcsv($stream, [$key, $this->safeCsv((string) $value)], escape: '');
                }
                fputcsv($stream, [], escape: '');
                fputcsv($stream, $headers, escape: '');
                foreach ($table as $row) {
                    fputcsv($stream, array_map($this->safeCsv(...), $row), escape: '');
                }
                rewind($stream);
                $bytes = stream_get_contents($stream);
                $mime = 'text/csv; charset=UTF-8';
            } elseif ($format === 'xlsx') {
                $book = new Spreadsheet;
                try {
                    $sheet = $book->getActiveSheet();
                    $sheet->setTitle('Report');
                    $line = 1;
                    $all = array_merge(array_map(fn ($k, $v) => [$k, $v], array_keys($metadata), array_values($metadata)), [[]], [$headers], $table);
                    foreach ($all as $row) {
                        foreach ($row as $col => $value) {
                            $sheet->setCellValueExplicit([$col + 1, $line], (string) $value, DataType::TYPE_STRING);
                        } $line++;
                    }
                    $sheet->freezePane('A'.(count($metadata) + 3));
                    $sheet->getColumnDimension('A')->setWidth(25);
                    (new Xlsx($book))->save($stream);
                    rewind($stream);
                    $bytes = stream_get_contents($stream);
                } finally {
                    $book->disconnectWorksheets();
                }
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                $escape = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html = '<style>@page{margin:24px}body{font:9px DejaVu Sans;color:#172b32}table{width:100%;border-collapse:collapse;table-layout:fixed}td,th{padding:4px;border:1px solid #ccd4d8;overflow-wrap:anywhere}th{background:#edf2f4}thead{display:table-header-group}</style><h1>Thryve '.$escape($report).'</h1>';
                foreach ($metadata as $k => $v) {
                    $html .= '<p><b>'.$escape($k).':</b> '.$escape($v).'</p>';
                }
                $html .= '<table><thead><tr><th>Record</th><th>Data</th></tr></thead><tbody>';
                foreach ($rows as $i => $row) {
                    $html .= '<tr><td>'.($i + 1).'</td><td>';
                    foreach ($row as $k => $v) {
                        $html .= '<b>'.$escape($k).':</b> '.$escape($this->cell($v)).'<br>';
                    }$html .= '</td></tr>';
                }
                $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false]);
                $pdf->loadHtml($html.'</tbody></table>');
                $pdf->setPaper('A4');
                $pdf->render();
                $bytes = $pdf->output();
                $mime = 'application/pdf';
            }
        } finally {
            fclose($stream);
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'rows' => count($rows)];
    }

    /** Columns that hold a moment in time. Stored in UTC; exports show them in business time with the zone in the heading. */
    private const TIME_COLUMNS = ['at', 'created_at', 'last_message_at', 'archived_at', 'deleted_at', 'first_in_at', 'last_out_at'];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function inBusinessTime(array $rows): array
    {
        $label = BusinessDay::label();

        return array_map(function (array $row) use ($label) {
            $out = [];
            foreach ($row as $key => $value) {
                if (in_array($key, self::TIME_COLUMNS, true)) {
                    $out["{$key} ({$label})"] = BusinessDay::stamp($value);
                } else {
                    $out[$key] = $value;
                }
            }

            return $out;
        }, $rows);
    }

    private function people(ReportFilters $f, int $limit): array
    {
        return app(OperationalReports::class)->people($f, $limit + 1, 1)->getCollection()->map(fn ($r) => (array) $r)->all();
    }

    private function summary(string $report, ReportFilters $f): array
    {
        $data = match ($report) {
            'usage' => app(UsageReports::class)->data($f),'quality','agents' => app(QualityReports::class)->data($f),'adoption' => app(AdoptionReports::class)->data($f),default => app(OperationalReports::class)->overview($f)
        };
        if ($report === 'agents') {
            $data = ['agents' => $data['agents'], 'scope' => 'Top 100 assistants by attempts, matching the quality dashboard.'];
        }
        if ($report === 'adoption') {
            // Paginated matrix and today's allowances are UI inspection views, not a complete historical export.
            unset($data['matrix'],$data['limits']);
            $data['scope'] = 'Aggregate adoption; use the People export for all people. Matrix and current allowance pages are excluded.';
        }
        $rows = [];
        $walk = function ($value, $path) use (&$walk, &$rows) {
            if (is_object($value)) {
                $value = json_decode(json_encode($value), true);
            }
            if (is_array($value)) {
                foreach ($value as $key => $v) {
                    $walk($v, $path === '' ? (string) $key : $path.'.'.$key);
                }
            } else {
                $rows[] = ['field' => $path, 'value' => $value];
            }
        };
        $walk($data, '');

        return $rows;
    }

    private function cell(mixed $value): string
    {
        return is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : (is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? 'Not recorded' : (string) $value));
    }

    private function safeCsv(string $value): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
