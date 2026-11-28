<?php

namespace App\Ai;

use App\Models\Attachment;
use App\Models\Message;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/** Strict data-only generation contract. Model output is never executed or interpreted as HTML. */
final class GeneratedFiles
{
    public const FORMATS = ['csv', 'md', 'docx', 'xlsx', 'pdf', 'pptx'];

    public static function instruction(string $format): string
    {
        return 'Thryve creates the requested '.$format.' file locally. Return ONLY valid JSON, no fences, paths or links. '
            .'Schema: {"version":1,"message":"Short reply for the employee","files":[{"format":"'.$format.'","name":"report","title":"Title","paragraphs":["Plain text paragraphs"],"rows":[["Header","Value"],["Item",123]],"slides":[{"title":"Slide title","bullets":["Point"]}]}]}. '
            .'Return exactly one file. For csv/xlsx supply rows; for pptx supply slides; for md/docx/pdf supply paragraphs. '
            .'No HTML, code, formulas, macros or external resources. Use only authorized conversation data; label unknown figures, never invent results. '
            .'Limits: 100 paragraphs (2000 characters each), 500 rows of 30 cells (500 characters each), 20 slides with 5 bullets (120 characters each); slide titles 80 characters. '
            .'Use prose in message. If you cannot fulfill the request safely, return {"version":1,"message":"Explain the limitation","files":[]}.';
    }

    /** The marker that wraps one file description inside an ordinary chat reply (automatic mode). */
    public const BLOCK = 'thryve-file';

    /**
     * Automatic mode: no format is chosen by the person. The agent answers in rich Markdown chat text unless a downloadable file is clearly
     * wanted, and then adds ONE marked block that Thryve turns into the file locally. Nothing in the block is ever executed.
     */
    public static function autoInstruction(): string
    {
        return 'Write every chat reply in GitHub-flavoured Markdown; the chat renders it as rich text. Format like a polished assistant: '
            .'open with the direct answer in a sentence or two, then structure the rest. Use `##`/`###` headings for answers with several parts, '
            .'`-` bullets or `1.` numbered steps for lists and procedures (nest with two spaces), `**bold**` for key terms, totals and required actions, '
            .'a pipe table (header row, then a `|---|` separator row) whenever you compare items or show several fields per item (emails, files, prices, options, metrics), '
            .'fenced code blocks with a language word (```php, ```bash, ```json) for code, commands, logs or raw data, `inline code` for names and paths, `>` quotes for quoted text, and `---` between major sections. '
            .'Never draw a table with spaces or tabs, and never put a table inside a code block. Keep paragraphs short. '
            .'A greeting or a one-line answer stays a short sentence without headings; a drafted email or message keeps its normal letter layout. '
            .'Only when the person clearly asks for a downloadable file '
            .'(CSV, Markdown, Word, Excel, PDF or PowerPoint) should you create one: write a short note in plain text, then add exactly ONE block like this, with the fence word '.self::BLOCK.' and valid JSON inside, no links or paths: '
            .'```'.self::BLOCK."\n".'{"format":"docx","name":"report","title":"Title","paragraphs":["Plain text paragraphs"],"rows":[["Header","Value"],["Item",123]],"slides":[{"title":"Slide title","bullets":["Point"]}]}'."\n```\n"
            .'"format" is one of csv, md, docx, xlsx, pdf, pptx. For csv/xlsx supply rows; for pptx supply slides; for md/docx/pdf supply paragraphs. '
            .'Never use the block for a normal answer, for code, or to show a table in chat: a table or list the person only wants to read stays in the chat. '
            .'No HTML, code, formulas, macros or external resources inside the block. Use only authorized conversation data; label unknown figures, never invent results. '
            .'Limits: 100 paragraphs (2000 characters each), 500 rows of 30 cells (500 characters each), 20 slides with 5 bullets (120 characters each); slide titles 80 characters. '
            .'If you cannot make the file safely, explain why in plain text and add no block.';
    }

    /**
     * Automatic mode: finds the marked block in a reply. Returns null for an ordinary chat reply, or the note and the rendered file.
     * A reply that has the marker but is cut off, repeated, or not valid throws, so nobody is ever shown raw JSON or a broken download.
     *
     * @return array{message: string, files: array}|null
     */
    public function extractAuto(string $content, bool $cutOff = false): ?array
    {
        $opening = substr_count($content, '```'.self::BLOCK);
        if (preg_match_all('/```[ \t]*'.self::BLOCK.'\b/i', $content) !== $opening) {
            throw new FileGenerationFailed;
        }
        if ($opening === 0) {
            return null;
        }
        if ($cutOff || $opening > 1 || strlen($content) > 300000 || ! preg_match('/```'.self::BLOCK.'[ \t]*\R(.*?)\R?```/s', $content, $m, PREG_OFFSET_CAPTURE)) {
            throw new FileGenerationFailed;
        }
        try {
            $spec = json_decode($m[1][0], true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($spec)) {
                throw new FileGenerationFailed;
            }
            $file = $this->render($spec);
        } catch (\Throwable $e) {
            throw $this->refused($e);
        }
        $note = trim((string) preg_replace('/(\R\s*){3,}/', "\n\n", rtrim(substr($content, 0, $m[0][1]))."\n\n".ltrim(substr($content, $m[0][1] + strlen($m[0][0])))));

        return ['message' => mb_substr($note !== '' ? $note : 'Here is your file.', 0, 4000), 'files' => [$file]];
    }

    public function decode(string $content, string $format): array
    {
        try {
            if (strlen($content) > 300000) {
                throw new FileGenerationFailed;
            }
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            Validator::make($data, [
                'version' => ['required', 'integer', 'in:1'],
                'message' => ['required', 'string', 'max:4000'],
                'files' => ['present', 'array', 'max:1'],
            ])->validate();
            $rendered = [];
            foreach ($data['files'] as $spec) {
                if (($spec['format'] ?? null) !== $format) {
                    throw new FileGenerationFailed;
                }
                $rendered[] = $this->render($spec);
            }

            return ['message' => $data['message'], 'files' => $rendered];
        } catch (\Throwable $e) {
            throw $this->refused($e);
        }
    }

    /**
     * Repair what a model gets slightly wrong so a good deck is not thrown away: missing or empty bullets (a title-only
     * slide), long text (cut with an ellipsis) and more than five bullets (continued on the next slide). Anything that
     * is not plain text is left alone, so the validation in render() still refuses it.
     */
    private function normalizeSlides(array $slides): array
    {
        $out = [];
        foreach (array_values($slides) as $slide) {
            $bullets = is_array($slide) ? ($slide['bullets'] ?? []) : null;
            if (! is_array($slide) || ! is_string($slide['title'] ?? null) || trim($slide['title']) === ''
                || ! is_array($bullets) || array_filter($bullets, fn ($b) => ! is_string($b))) {
                $out[] = $slide;

                continue;
            }
            $title = $this->clip(trim($slide['title']), 80);
            $bullets = array_values(array_filter(array_map(fn ($b) => $this->clip(trim($b), 120), $bullets), fn ($b) => $b !== ''));
            foreach (array_chunk($bullets, 5) ?: [[]] as $i => $chunk) {
                $out[] = ['title' => $i === 0 ? $title : $this->clip($title, 70).' (cont.)', 'bullets' => $chunk];
            }
        }

        return array_slice($out, 0, 20);
    }

    private function clip(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /** Record why a file was refused (field names and rules only, never the content) and return the generic error people see. */
    private function refused(\Throwable $e): FileGenerationFailed
    {
        $why = $e instanceof ValidationException
            ? implode(' | ', array_slice(array_map(fn ($messages) => $messages[0], $e->errors()), 0, 5))
            : $e::class.($e instanceof \JsonException ? ': '.$e->getMessage() : '');
        Log::warning('Generated file refused: '.$why);

        return $e instanceof FileGenerationFailed ? $e : new FileGenerationFailed;
    }

    public function render(array $spec): array
    {
        if (($spec['format'] ?? null) === 'pptx' && is_array($spec['slides'] ?? null)) {
            $spec['slides'] = $this->normalizeSlides($spec['slides']);
        }
        Validator::make($spec, [
            'format' => ['required', 'in:'.implode(',', self::FORMATS)],
            'name' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:150'],
            'paragraphs' => ['sometimes', 'array', 'max:100'],
            'paragraphs.*' => ['string', 'max:2000'],
            'rows' => ['sometimes', 'array', 'max:500'],
            'rows.*' => ['array', 'max:30'],
            'slides' => ['sometimes', 'array', 'max:20'],
            'slides.*.title' => ['required', 'string', 'max:80'],
            'slides.*.bullets' => ['present', 'array', 'max:5'],
            'slides.*.bullets.*' => ['string', 'max:120'],
        ])->validate();
        $format = $spec['format'];
        $field = in_array($format, ['csv', 'xlsx']) ? 'rows' : ($format === 'pptx' ? 'slides' : 'paragraphs');
        if (empty($spec[$field])) {
            throw new FileGenerationFailed;
        }
        foreach ($spec['rows'] ?? [] as $row) {
            foreach ($row as $cell) {
                if ((! is_string($cell) && ! is_int($cell) && ! is_float($cell) && $cell !== null) || mb_strlen((string) $cell) > 500) {
                    throw new FileGenerationFailed;
                }
            }
        }
        $name = Str::slug(pathinfo($spec['name'], PATHINFO_FILENAME)) ?: 'report';
        $temp = tempnam(sys_get_temp_dir(), 'thryve-artifact-');
        try {
            $bytes = match ($format) {
                'md' => '# '.$spec['title']."\n\n".implode("\n\n", $spec['paragraphs'])."\n",
                'csv' => $this->csv($spec['rows']),
                'pdf' => $this->pdf($spec),
                'docx' => $this->docx($spec, $temp),
                'xlsx' => $this->xlsx($spec, $temp),
                'pptx' => $this->pptx($spec, $temp),
            };
            if (strlen($bytes) === 0 || strlen($bytes) > 15 * 1024 * 1024) {
                throw new FileGenerationFailed;
            }

            return ['bytes' => $bytes, 'name' => $name.'.'.$format, 'format' => $format];
        } finally {
            @unlink($temp);
        }
    }

    /** Persist all files atomically with the assistant message; clean written bytes on any failure. */
    public function save(int $conversationId, int $userId, string $requestId, array $result, ?string $model = null): Message
    {
        $keys = [];
        try {
            return DB::transaction(function () use ($conversationId, $userId, $requestId, $result, $model, &$keys) {
                $message = Message::create(['conversation_id' => $conversationId, 'role' => 'assistant', 'content' => $result['message'], 'request_id' => $requestId, 'model' => $model]);
                foreach ($result['files'] as $file) {
                    $key = 'generated/'.Str::random(40).'.bin';
                    $keys[] = $key;
                    if (! Storage::disk('private')->put($key, $file['bytes'])) {
                        throw new FileGenerationFailed;
                    }
                    Attachment::create(['user_id' => $userId, 'conversation_id' => $conversationId, 'message_id' => $message->id,
                        'original_name' => $file['name'], 'kind' => $file['format'], 'size' => strlen($file['bytes']), 'storage_key' => $key,
                        'sha256' => hash('sha256', $file['bytes']), 'extracted_text' => '', 'truncated' => false, 'generated' => true]);
                }

                return $message;
            });
        } catch (\Throwable) {
            Storage::disk('private')->delete($keys);
            throw new FileGenerationFailed;
        }
    }

    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($v) => is_string($v) && preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $v) ? "'".$v : $v, $row), ',', '"', '');
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    private function pdf(array $spec): string
    {
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options);
        $pdf->loadHtml('<meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:11pt;color:#0f172a}p{line-height:1.5}</style><h1>'.e($spec['title']).'</h1>'.implode('', array_map(fn ($p) => '<p>'.nl2br(e($p)).'</p>', $spec['paragraphs'])));
        $pdf->render();

        return $pdf->output();
    }

    private function docx(array $spec, string $temp): string
    {
        Settings::setOutputEscapingEnabled(true);
        $doc = new PhpWord;
        $section = $doc->addSection();
        $section->addText($spec['title'], ['bold' => true, 'size' => 20]);
        foreach ($spec['paragraphs'] as $p) {
            $section->addText($p);
        }
        IOFactory::createWriter($doc, 'Word2007')->save($temp);

        return file_get_contents($temp);
    }

    private function xlsx(array $spec, string $temp): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ($spec['rows'] as $r => $row) {
            foreach ($row as $c => $v) {
                $sheet->setCellValueExplicit([$c + 1, $r + 1], $v ?? '', is_int($v) || is_float($v) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($temp);
        $book->disconnectWorksheets();

        return file_get_contents($temp);
    }

    private function pptx(array $spec, string $temp): string
    {
        copy(resource_path('templates/report.pptx'), $temp);
        $zip = new \ZipArchive;
        if ($zip->open($temp) !== true) {
            throw new FileGenerationFailed;
        }
        $template = $zip->getFromName('ppt/slides/slide1.xml');
        $rels = $zip->getFromName('ppt/slides/_rels/slide1.xml.rels');
        $presentation = new \DOMDocument;
        $presentation->loadXML($zip->getFromName('ppt/presentation.xml'));
        $ids = $presentation->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldIdLst')->item(0);
        while ($ids->firstChild) {
            $ids->removeChild($ids->firstChild);
        }
        $relationships = new \DOMDocument;
        $relationships->loadXML($zip->getFromName('ppt/_rels/presentation.xml.rels'));
        foreach (iterator_to_array($relationships->documentElement->childNodes) as $node) {
            if ($node instanceof \DOMElement && str_ends_with($node->getAttribute('Type'), '/slide')) {
                $node->parentNode->removeChild($node);
            }
        }
        $types = new \DOMDocument;
        $types->loadXML($zip->getFromName('[Content_Types].xml'));
        foreach ($spec['slides'] as $index => $slide) {
            $n = $index + 1;
            $xml = new \DOMDocument;
            $xml->loadXML($template);
            foreach ($xml->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't') as $text) {
                if ($text->textContent === 'THRYVE_TITLE') {
                    $text->textContent = $slide['title'];
                } elseif ($text->textContent === 'THRYVE_BODY') {
                    if ($slide['bullets'] === []) {
                        // A title-only slide keeps its (now empty) body paragraph: a text body must hold at least one.
                        $text->textContent = '';
                        break;
                    }
                    $paragraph = $text->parentNode->parentNode;
                    $parent = $paragraph->parentNode;
                    foreach ($slide['bullets'] as $bullet) {
                        $clone = $paragraph->cloneNode(true);
                        $clone->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't')->item(0)->textContent = $bullet;
                        $parent->insertBefore($clone, $paragraph);
                    }
                    $parent->removeChild($paragraph);
                    break;
                }
            }
            $zip->addFromString("ppt/slides/slide{$n}.xml", $xml->saveXML());
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", $rels);
            $id = $presentation->createElementNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'p:sldId');
            $id->setAttribute('id', (string) (255 + $n));
            $id->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', 'thryveSlide'.$n);
            $ids->appendChild($id);
            $rel = $relationships->createElement('Relationship');
            $rel->setAttribute('Id', 'thryveSlide'.$n);
            $rel->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide');
            $rel->setAttribute('Target', "slides/slide{$n}.xml");
            $relationships->documentElement->appendChild($rel);
            if ($n > 1) {
                $type = $types->createElement('Override');
                $type->setAttribute('PartName', "/ppt/slides/slide{$n}.xml");
                $type->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml');
                $types->documentElement->appendChild($type);
            }
        }
        $zip->addFromString('ppt/presentation.xml', $presentation->saveXML());
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $relationships->saveXML());
        $zip->addFromString('[Content_Types].xml', $types->saveXML());
        $zip->close();

        return file_get_contents($temp);
    }
}
