<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Field;
use App\Models\Signer;
use App\Support\CanonicalJson;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final readonly class PdfCompletionRenderer
{
    private const array IMPORT_OPTIONS = [
        'box' => 'CropBox',
        'respectRotation' => true,
        'groupXObject' => true,
        'cache' => true,
    ];

    public function __construct(
        private CanonicalJson $canonicalJson,
        private PdfFontCoverage $fontCoverage,
    ) {}

    public function render(Envelope $envelope): CompletedPdf
    {
        $envelope->loadMissing(['document', 'sender', 'signers.fields', 'auditEvents']);
        $document = $envelope->document;

        if ($document === null || ! Storage::exists($document->storage_path)) {
            throw new RuntimeException('The original document is unavailable.');
        }

        $sourceBytes = Storage::get($document->storage_path);
        if (strlen($sourceBytes) > PdfDocumentService::MAX_BYTES
            || ! hash_equals($document->original_sha256, hash('sha256', $sourceBytes))) {
            throw new RuntimeException('The original document failed its integrity check.');
        }

        $this->assertFonts();
        $pdf = new Tcpdf(unit: 'pt', isunicode: true, subsetfont: true, compress: true);
        $pdf->setPDFFilename('completed.pdf');
        $textFont = $pdf->font->insert($pdf->pon, 'notosans', '', 9);
        $scriptFont = $pdf->font->insert($pdf->pon, 'pacifico', '', 12);
        $sourceId = $pdf->setImportSourceData($sourceBytes, [
            'strict_limits' => true,
            'max_stream_size' => 32 * 1024 * 1024,
        ]);
        $sourcePageCount = $pdf->getSourcePageCount($sourceId);

        if ($sourcePageCount !== $document->page_count) {
            throw new RuntimeException('The original document page count changed.');
        }

        /** @var Collection<int, Collection<int, Field>> $fieldsByPage */
        $fieldsByPage = $envelope->fields()->with('signer')->orderBy('id')->get()->groupBy('page');

        for ($pageNumber = 1; $pageNumber <= $sourcePageCount; $pageNumber++) {
            $template = $pdf->importPage($sourceId, $pageNumber, self::IMPORT_OPTIONS);
            $pageHeight = $template->getHeight();
            $pageWidth = $template->getWidth();
            $pdf->addPage([
                'format' => '',
                'width' => $pageWidth,
                'height' => $pageHeight,
                'orientation' => $pageWidth > $pageHeight ? 'L' : 'P',
            ]);

            $cropBox = $template->getMediaBox();
            // tc-lib's rotation matrices assume a zero-origin selected box. Its y placement uses top-left coordinates.
            [$placementX, $placementY] = match ($template->getRotation()) {
                0 => [-$cropBox[0], $cropBox[1]],
                90 => [-$cropBox[1], -$cropBox[0]],
                180 => [$cropBox[0], -$cropBox[1]],
                270 => [$cropBox[1], $cropBox[0]],
                default => throw new RuntimeException('The source page has an unsupported rotation.'),
            };
            $pdf->useImportedPage(
                $template,
                $placementX,
                $placementY,
                $pageWidth,
                $pageHeight,
                ['keepAspectRatio' => false],
            );

            foreach ($fieldsByPage->get($pageNumber, collect()) as $field) {
                if ($field->value === null || $field->completed_at === null) {
                    continue;
                }

                $x = (float) $field->position_x * $pageWidth;
                $y = (float) $field->position_y * $pageHeight;
                $width = (float) $field->width * $pageWidth;
                $height = (float) $field->height * $pageHeight;

                if (($field->value['kind'] ?? null) === 'drawn') {
                    $image = $this->normalizedDrawing($field);
                    $imageId = $pdf->image->add('@'.$image);
                    $pdf->page->addContent($pdf->image->getSetImage(
                        $imageId,
                        $x,
                        $y,
                        $width,
                        $height,
                        $pageHeight,
                    ));

                    continue;
                }

                $typed = ($field->value['kind'] ?? null) === 'typed';
                $font = $typed ? $scriptFont : $textFont;
                $fontName = $typed ? PdfFontCoverage::SCRIPT : PdfFontCoverage::TEXT;
                $text = $this->fieldText($field);
                $this->fontCoverage->assertSupported($fontName, $text);
                $pdf->page->addContent(sprintf(
                    "q %F %F %F %F re W n\n",
                    $x,
                    $pageHeight - $y - $height,
                    $width,
                    $height,
                ));
                $pdf->page->addContent($this->textCell(
                    pdf: $pdf,
                    font: $font,
                    text: $text,
                    posx: $x,
                    posy: $y,
                    width: $width,
                    height: $height,
                    valign: 'C',
                    halign: $field->type === FieldType::Text ? 'L' : 'C',
                    drawcell: false,
                    fit: 'F',
                ));
                $pdf->page->addContent("Q\n");
            }
        }

        $chainHead = (string) $envelope->audit_chain_head;
        $contentSha256 = hash('sha256', $this->canonicalJson->encode([
            'definition' => 'Witness final content v1: original SHA-256, displayed field responses, and pre-completion audit chain head.',
            'envelope_id' => $envelope->getKey(),
            'original_sha256' => $document->original_sha256,
            'fields' => $this->contentFields($fieldsByPage),
            'chain_head' => $chainHead,
        ]));
        $certificatePageCount = $this->appendCertificate(
            $pdf,
            $envelope,
            $textFont,
            $contentSha256,
            $chainHead,
        );
        $finalBytes = $pdf->getOutPDFString();
        $this->assertNoWarnings($pdf);

        if (strlen($finalBytes) > 32 * 1024 * 1024) {
            throw new RuntimeException('The final document exceeded the safe output limit.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'witness-final-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate final document validation storage.');
        }

        try {
            if (file_put_contents($temporaryPath, $finalBytes, LOCK_EX) !== strlen($finalBytes)) {
                throw new RuntimeException('Unable to write the final document for validation.');
            }

            $validation = new Tcpdf;
            $validationSource = $validation->setImportSourceFile($temporaryPath, [
                'strict_limits' => true,
                'max_stream_size' => 32 * 1024 * 1024,
            ]);
            $expectedPages = $sourcePageCount + $certificatePageCount;

            if ($validation->getSourcePageCount($validationSource) !== $expectedPages) {
                throw new RuntimeException('The final document page count is not valid.');
            }

            $this->assertNoWarnings($validation);
        } finally {
            @unlink($temporaryPath);
        }

        return new CompletedPdf(
            bytes: $finalBytes,
            sha256: hash('sha256', $finalBytes),
            contentSha256: $contentSha256,
            sourcePageCount: $sourcePageCount,
            certificatePageCount: $certificatePageCount,
            certificateChainHead: $chainHead,
        );
    }

    /** @param array<string, mixed> $font */
    private function appendCertificate(
        Tcpdf $pdf,
        Envelope $envelope,
        array $font,
        string $contentSha256,
        string $chainHead,
    ): int {
        $lines = [
            'Certificate of Completion',
            '',
            'Envelope ID: '.$envelope->getKey(),
            'Title: '.$envelope->title,
            'Sender: '.$envelope->sender->name.' <'.$envelope->sender->email.'>',
            'Original document SHA-256: '.$envelope->document->original_sha256,
            'Final-content SHA-256: '.$contentSha256,
            'Final-content definition: Witness final content v1 hashes the original SHA-256, displayed field responses, and the pre-completion audit chain head. It excludes this rendered PDF container and certificate pages.',
            'Certificate audit chain head: '.$chainHead,
            'Verify URL: '.route('verify.index'),
            '',
            'Signers',
        ];

        foreach ($envelope->signers->sortBy('position') as $signer) {
            $lines = [...$lines, ...$this->signerLines($signer, $envelope->auditEvents)];
        }

        $lines[] = '';
        $lines[] = 'Audit events through the final signature (completion and delivery events are appended after this PDF is hashed):';
        foreach ($envelope->auditEvents as $event) {
            $actor = $event->actor_type->value.($event->actor_id === null ? '' : ':'.$event->actor_id);
            $payload = $this->canonicalJson->encode($event->payload);
            $lines[] = sprintf(
                '#%d %s %s actor=%s ip=%s user-agent=%s payload=%s',
                $event->sequence,
                $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                $event->type->value,
                $actor,
                $event->ip_address ?? '-',
                $event->user_agent ?? '-',
                $payload,
            );
        }

        $wrapped = [];
        foreach ($lines as $line) {
            array_push($wrapped, ...$this->wrap($line, 96));
        }
        $this->fontCoverage->assertSupported(PdfFontCoverage::TEXT, implode("\n", $wrapped));

        $pageCount = 0;
        $y = 0.0;
        foreach ($wrapped as $line) {
            if ($pageCount === 0 || $y > 742) {
                $pdf->addPage(['format' => '', 'width' => 612, 'height' => 792, 'orientation' => 'P']);
                $pageCount++;
                $y = 42;

                if ($pageCount > 1) {
                    $pdf->page->addContent($this->textCell(
                        pdf: $pdf,
                        font: $font,
                        text: 'Certificate of Completion (continued)',
                        posx: 42,
                        posy: $y,
                        width: 528,
                        height: 12,
                        valign: 'T',
                        halign: 'L',
                        drawcell: false,
                    ));
                    $y += 16;
                }
            }

            $pdf->page->addContent($this->textCell(
                pdf: $pdf,
                font: $font,
                text: $line,
                posx: 42,
                posy: $y,
                width: 528,
                height: 11,
                valign: 'T',
                halign: 'L',
                drawcell: false,
            ));
            $y += 11;
        }

        return $pageCount;
    }

    /** @param Collection<int, AuditEvent> $events
     * @return list<string>
     */
    private function signerLines(Signer $signer, Collection $events): array
    {
        $forSigner = $events->where('actor_type', AuditActorType::Signer)
            ->where('actor_id', (string) $signer->getKey());
        $eventTime = static fn (AuditEventType $type): string => $forSigner
            ->firstWhere('type', $type)?->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z') ?? '-';
        $signed = $forSigner->firstWhere('type', AuditEventType::Signed);
        $modes = $signer->fields
            ->whereIn('type', [FieldType::Signature, FieldType::Initials])
            ->map(fn (Field $field): mixed => $field->value['kind'] ?? null)
            ->filter(fn (mixed $mode): bool => is_string($mode))
            ->unique()
            ->implode(', ');

        return [
            $signer->name.' <'.$signer->email.'>',
            '  IP: '.($signed->ip_address ?? '-'),
            '  Consent: '.$eventTime(AuditEventType::Consented),
            '  Code verified: '.$eventTime(AuditEventType::CodeVerified),
            '  Signed: '.$eventTime(AuditEventType::Signed),
            '  Signature mode: '.($modes === '' ? '-' : $modes),
        ];
    }

    /** @param Collection<int, Collection<int, Field>> $fieldsByPage
     * @return list<array<string, mixed>>
     */
    private function contentFields(Collection $fieldsByPage): array
    {
        return $fieldsByPage->flatten(1)->map(function (Field $field): array {
            $value = $field->value ?? [];
            if (($value['kind'] ?? null) === 'drawn') {
                $value = ['kind' => 'drawn', 'sha256' => $value['sha256'] ?? null];
            }

            return [
                'id' => $field->getKey(),
                'page' => $field->page,
                'type' => $field->type->value,
                'x' => $field->position_x,
                'y' => $field->position_y,
                'width' => $field->width,
                'height' => $field->height,
                'value' => $value,
            ];
        })->values()->all();
    }

    private function fieldText(Field $field): string
    {
        return match ($field->type) {
            FieldType::Checkbox => ($field->value['checked'] ?? false) === true ? 'X' : '',
            FieldType::Signature, FieldType::Initials => (string) ($field->value['text'] ?? ''),
            FieldType::DateSigned, FieldType::Text => (string) ($field->value['value'] ?? ''),
        };
    }

    /** @param array<string, mixed> $font */
    private function textCell(
        Tcpdf $pdf,
        array $font,
        string $text,
        float $posx,
        float $posy,
        float $width,
        float $height,
        string $valign,
        string $halign,
        bool $drawcell,
        string $fit = '',
    ): string {
        $pdf->font->cloneFont($pdf->pon, $font['idx']);

        try {
            return $pdf->getTextCell(
                txt: $text,
                posx: $posx,
                posy: $posy,
                width: $width,
                height: $height,
                valign: $valign,
                halign: $halign,
                drawcell: $drawcell,
                fit: $fit,
            );
        } finally {
            $pdf->font->popLastFont();
        }
    }

    private function normalizedDrawing(Field $field): string
    {
        $path = $field->value['path'] ?? null;
        $expectedHash = $field->value['sha256'] ?? null;
        if (! is_string($path) || ! is_string($expectedHash) || ! Storage::exists($path)) {
            throw new RuntimeException('A drawn signature is unavailable.');
        }

        $bytes = Storage::get($path);
        if (strlen($bytes) > 512 * 1024 || ! hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('A drawn signature failed its integrity check.');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) > 2000 || imagesy($image) > 1000) {
            throw new RuntimeException('A drawn signature could not be decoded safely.');
        }

        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            if (! imagepng($image, null, 9)) {
                throw new RuntimeException('A drawn signature could not be normalized.');
            }
            $normalized = ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        } finally {
            imagedestroy($image);
        }

        if (! is_string($normalized) || strlen($normalized) > 1024 * 1024) {
            throw new RuntimeException('A drawn signature exceeded its normalized limit.');
        }

        return $normalized;
    }

    /** @return list<string> */
    private function wrap(string $line, int $width): array
    {
        if ($line === '') {
            return [''];
        }

        $lines = [];
        while (mb_strlen($line) > $width) {
            $candidate = mb_substr($line, 0, $width + 1);
            $break = mb_strrpos($candidate, ' ');
            $take = $break === false || $break < 20 ? $width : $break;
            $lines[] = mb_substr($line, 0, $take);
            $line = ltrim(mb_substr($line, $take));
        }
        $lines[] = $line;

        return $lines;
    }

    private function assertFonts(): void
    {
        $directory = resource_path('fonts/generated');
        foreach (['pacifico.json', 'notosans.json'] as $definition) {
            if (! is_file($directory.DIRECTORY_SEPARATOR.$definition)) {
                throw new RuntimeException('Required PDF fonts have not been generated.');
            }
        }

        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $directory);
        }
    }

    private function assertNoWarnings(Tcpdf $pdf): void
    {
        if ($pdf->getWarnings() !== []) {
            throw new RuntimeException('The PDF library reported a strict processing warning.');
        }
    }
}
