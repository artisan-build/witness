<?php

declare(strict_types=1);

const FIXTURE_DIRECTORY = __DIR__;

/** @param array<int, string> $objects */
function classicPdf(array $objects, int $root = 1, ?int $encrypt = null, string $version = '1.4'): string
{
    $pdf = "%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $size = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    for ($number = 1; $number < $size; $number++) {
        $pdf .= isset($offsets[$number])
            ? sprintf('%010d 00000 n ', $offsets[$number])."\n"
            : "0000000000 00000 f \n";
    }

    $encryptEntry = $encrypt === null ? '' : " /Encrypt {$encrypt} 0 R";

    return $pdf."trailer\n<< /Size {$size} /Root {$root} 0 R{$encryptEntry} >>\nstartxref\n{$xref}\n%%EOF\n";
}

function streamObject(string $dictionary, string $bytes, bool $compress = true): string
{
    if ($compress) {
        $bytes = gzcompress($bytes, 9);
        $dictionary .= ' /Filter /FlateDecode';
    }

    return '<< '.$dictionary.' /Length '.strlen($bytes)." >>\nstream\n".$bytes."\nendstream";
}

function literal(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function tinyRgb(): string
{
    $bytes = '';
    for ($y = 0; $y < 16; $y++) {
        for ($x = 0; $x < 16; $x++) {
            $bytes .= chr(($x * 17) % 256).chr(($y * 17) % 256).chr((($x + $y) * 9) % 256);
        }
    }

    return $bytes;
}

function pageContent(string $label, float $width, float $height, bool $withImage = true): string
{
    $top = $height - 34;
    $right = $width - 12;
    $bottom = 12;
    $content = 'q 1 w 0 0 0 RG 12 12 '.($width - 24).' '.($height - 24)." re S Q\n";
    $content .= "q 1 0 0 rg 6 {$top} 6 6 re f Q\n";
    $content .= "q 0 1 0 rg {$right} {$top} 6 6 re f Q\n";
    $content .= "q 0 0 1 rg 6 {$bottom} 6 6 re f Q\n";
    $content .= "q 1 0 1 rg {$right} {$bottom} 6 6 re f Q\n";
    $content .= 'q 1 0.5 0 rg '.($width / 2 - 3).' '.($height / 2 - 3)." 6 6 re f Q\n";
    $content .= 'BT /F1 14 Tf 36 '.($height - 58).' Td ('.literal($label).") Tj ET\n";
    if ($withImage) {
        $content .= "q 48 0 0 48 36 36 cm /Im1 Do Q\n";
    }

    return $content;
}

/** @param list<float|int> $crop */
function croppedPageContent(string $label, array $crop): string
{
    [$x0, $y0, $x1, $y1] = array_map(static fn (float|int $value): float => (float) $value, $crop);
    $width = $x1 - $x0;
    $height = $y1 - $y0;
    $left = $x0 + 6;
    $right = $x1 - 12;
    $top = $y1 - 12;
    $bottom = $y0 + 6;
    $content = 'q 1 w 0 0 0 RG '.($x0 + 2).' '.($y0 + 2).' '.($width - 4).' '.($height - 4)." re S Q\n";
    $content .= "q 1 0 0 rg {$left} {$top} 6 6 re f Q\n";
    $content .= "q 0 1 0 rg {$right} {$top} 6 6 re f Q\n";
    $content .= "q 0 0 1 rg {$left} {$bottom} 6 6 re f Q\n";
    $content .= "q 1 0 1 rg {$right} {$bottom} 6 6 re f Q\n";
    $content .= 'q 1 0.5 0 rg '.(($x0 + $x1) / 2 - 3).' '.(($y0 + $y1) / 2 - 3)." 6 6 re f Q\n";
    $content .= 'BT /F1 14 Tf '.($x0 + 16).' '.($y1 - 28).' Td ('.literal($label).") Tj ET\n";

    return $content;
}

/** @param list<array{media: list<float|int>, crop: list<float|int>, rotate: int, label: string}> $pages */
function classicFixture(array $pages): string
{
    $pageCount = count($pages);
    $fontObject = 3 + ($pageCount * 2);
    $imageObject = $fontObject + 1;
    $kids = [];
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
    ];

    foreach ($pages as $index => $page) {
        $pageObject = 3 + ($index * 2);
        $contentObject = $pageObject + 1;
        $kids[] = "{$pageObject} 0 R";
        $media = implode(' ', $page['media']);
        $crop = implode(' ', $page['crop']);
        $resources = "<< /Font << /F1 {$fontObject} 0 R >> /XObject << /Im1 {$imageObject} 0 R >> >>";
        $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [{$media}] /CropBox [{$crop}] /Rotate {$page['rotate']} /Resources {$resources} /Contents {$contentObject} 0 R >>";
        $width = (float) $page['media'][2] - (float) $page['media'][0];
        $height = (float) $page['media'][3] - (float) $page['media'][1];
        $content = ($page['crop_fiducials'] ?? false) === true
            ? croppedPageContent($page['label'], $page['crop'])
            : pageContent($page['label'], $width, $height);
        $objects[$contentObject] = streamObject('', $content);
    }

    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count {$pageCount} >>";
    $objects[$fontObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[$imageObject] = streamObject('/Type /XObject /Subtype /Image /Width 16 /Height 16 /ColorSpace /DeviceRGB /BitsPerComponent 8', tinyRgb());
    ksort($objects);

    return classicPdf($objects);
}

function objectStreamFixture(): string
{
    $image = tinyRgb();
    $content = pageContent('OBJECT-STREAM-1.7', 595.276, 841.89);
    $compressedObjects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.276 841.89] /CropBox [0 0 595.276 841.89] /Rotate 0 /Resources << /Font << /F1 5 0 R >> /XObject << /Im1 6 0 R >> >> /Contents 4 0 R >>',
    ];
    $header = '';
    $body = '';
    foreach ($compressedObjects as $number => $object) {
        $header .= $number.' '.strlen($body).' ';
        $body .= $object.' ';
    }

    $objects = [
        4 => streamObject('', $content),
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        6 => streamObject('/Type /XObject /Subtype /Image /Width 16 /Height 16 /ColorSpace /DeviceRGB /BitsPerComponent 8', $image),
        7 => streamObject('/Type /ObjStm /N 3 /First '.strlen($header), $header.$body),
    ];
    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $entry = static fn (int $type, int $field2, int $field3): string => chr($type).pack('N', $field2).pack('n', $field3);
    $xref = $entry(0, 0, 65535)
        .$entry(2, 7, 0)
        .$entry(2, 7, 1)
        .$entry(2, 7, 2)
        .$entry(1, $offsets[4], 0)
        .$entry(1, $offsets[5], 0)
        .$entry(1, $offsets[6], 0)
        .$entry(1, $offsets[7], 0)
        .$entry(1, $xrefOffset, 0);
    $xrefObject = streamObject('/Type /XRef /Size 9 /Root 1 0 R /W [1 4 2] /Index [0 9]', $xref);

    return $pdf."8 0 obj\n{$xrefObject}\nendobj\nstartxref\n{$xrefOffset}\n%%EOF\n";
}

function scannedFixture(): string
{
    $width = 1200;
    $height = 1553;
    $rgb = '';
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $grid = $x % 100 < 3 || $y % 100 < 3 || abs($x - (int) (($y / $height) * $width)) < 3;
            $tone = $grid ? 25 : (($x + $y) % 40) + 205;
            $rgb .= chr($tone).chr($grid ? 70 : $tone).chr($grid ? 120 : $tone);
        }
    }

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /CropBox [0 0 612 792] /Rotate 0 /Resources << /XObject << /Scan 5 0 R >> >> /Contents 4 0 R >>',
        4 => streamObject('', 'q 612 0 0 792 0 0 cm /Scan Do Q'),
        5 => streamObject("/Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8", $rgb),
    ];

    return classicPdf($objects);
}

function encryptedFixture(): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
        4 => streamObject('', '0 0 100 100 re S'),
        5 => '<< /Filter /Standard /V 1 /R 2 /Length 40 /O <0000000000000000000000000000000000000000000000000000000000000000> /U <0000000000000000000000000000000000000000000000000000000000000000> /P -4 >>',
    ];

    return classicPdf($objects, encrypt: 5);
}

function loadProbe(string $path): void
{
    $width = 2600;
    $height = 2500;
    $bytes = '';
    $counter = 0;
    while (strlen($bytes) < $width * $height * 3) {
        $bytes .= hash('sha256', 'witness-load-probe-'.$counter++, true);
    }
    $bytes = substr($bytes, 0, $width * $height * 3);
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Probe 5 0 R >> >> /Contents 4 0 R >>',
        4 => streamObject('', 'q 612 0 0 792 0 0 cm /Probe Do Q'),
        5 => streamObject("/Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8", $bytes),
    ];
    file_put_contents($path, classicPdf($objects), LOCK_EX);
}

$loadProbePath = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--load-probe=')) {
        $loadProbePath = substr($argument, strlen('--load-probe='));
    }
}
if (is_string($loadProbePath) && $loadProbePath !== '') {
    loadProbe($loadProbePath);
    fwrite(STDOUT, $loadProbePath.' '.filesize($loadProbePath)." bytes\n");
    exit(0);
}

$fixtures = [
    'classic-1.4-letter.pdf' => [
        'bytes' => classicFixture([[
            'media' => [0, 0, 612, 792], 'crop' => [0, 0, 612, 792], 'rotate' => 0, 'label' => 'CLASSIC-1.4',
        ]]),
        'pages' => [['media_box' => [0, 0, 612, 792], 'crop_box' => [0, 0, 612, 792], 'rotation' => 0, 'displayed' => [612, 792]]],
    ],
    'object-stream-1.7-a4.pdf' => [
        'bytes' => objectStreamFixture(),
        'pages' => [['media_box' => [0, 0, 595.276, 841.89], 'crop_box' => [0, 0, 595.276, 841.89], 'rotation' => 0, 'displayed' => [595.276, 841.89]]],
    ],
    'rotated-mixed-sizes.pdf' => [
        'bytes' => classicFixture([
            ['media' => [0, 0, 612, 792], 'crop' => [0, 0, 612, 792], 'rotate' => 0, 'label' => 'PAGE 1 MediaBox 612x792 CropBox 612x792 Rotate 0'],
            ['media' => [0, 0, 300, 500], 'crop' => [20, 30, 280, 470], 'rotate' => 90, 'label' => 'PAGE 2 MediaBox 300x500 CropBox 20 30 280 470 Rotate 90'],
            ['media' => [0, 0, 1008, 612], 'crop' => [0, 0, 1008, 612], 'rotate' => 270, 'label' => 'PAGE 3 MediaBox 1008x612 CropBox 1008x612 Rotate 270'],
        ]),
        'pages' => [
            ['media_box' => [0, 0, 612, 792], 'crop_box' => [0, 0, 612, 792], 'rotation' => 0, 'displayed' => [612, 792]],
            ['media_box' => [0, 0, 300, 500], 'crop_box' => [20, 30, 280, 470], 'rotation' => 90, 'displayed' => [440, 260]],
            ['media_box' => [0, 0, 1008, 612], 'crop_box' => [0, 0, 1008, 612], 'rotation' => 270, 'displayed' => [612, 1008]],
        ],
    ],
    'nonzero-cropbox-rotations.pdf' => [
        'bytes' => classicFixture(array_map(
            static fn (int $rotation): array => [
                'media' => [0, 0, 300, 500],
                'crop' => [20, 30, 280, 470],
                'rotate' => $rotation,
                'label' => "CROPBOX ORIGIN Rotate {$rotation}",
                'crop_fiducials' => true,
            ],
            [0, 90, 180, 270],
        )),
        'pages' => array_map(
            static fn (int $rotation): array => [
                'media_box' => [0, 0, 300, 500],
                'crop_box' => [20, 30, 280, 470],
                'rotation' => $rotation,
                'displayed' => in_array($rotation, [90, 270], true) ? [440, 260] : [260, 440],
                'label' => "CROPBOX ORIGIN Rotate {$rotation}",
                'label_origin' => match ($rotation) {
                    0 => [16, 412],
                    90 => [412, 244],
                    180 => [244, 28],
                    270 => [28, 16],
                },
            ],
            [0, 90, 180, 270],
        ),
    ],
    'scanned-image-letter.pdf' => [
        'bytes' => scannedFixture(),
        'pages' => [['media_box' => [0, 0, 612, 792], 'crop_box' => [0, 0, 612, 792], 'rotation' => 0, 'displayed' => [612, 792]]],
    ],
    'encrypted-password.pdf' => [
        'bytes' => encryptedFixture(),
        'encrypted' => true,
        'pages' => [['media_box' => [0, 0, 612, 792], 'crop_box' => [0, 0, 612, 792], 'rotation' => 0, 'displayed' => [612, 792]]],
    ],
];

$manifest = ['generator' => 'tests/Fixtures/Pdf/generate.php', 'fixtures' => []];
foreach ($fixtures as $filename => $fixture) {
    $path = FIXTURE_DIRECTORY.DIRECTORY_SEPARATOR.$filename;
    file_put_contents($path, $fixture['bytes'], LOCK_EX);
    $manifest['fixtures'][$filename] = [
        'sha256' => hash('sha256', $fixture['bytes']),
        'bytes' => strlen($fixture['bytes']),
        'encrypted' => $fixture['encrypted'] ?? false,
        'pages' => $fixture['pages'],
    ];
}

file_put_contents(
    FIXTURE_DIRECTORY.DIRECTORY_SEPARATOR.'manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    LOCK_EX,
);
