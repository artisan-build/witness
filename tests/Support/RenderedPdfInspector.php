<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class RenderedPdfInspector
{
    /** @var array<int, string> */
    private array $objects = [];

    /** @var list<array{object: int, width: float, height: float, rotation: int, content: int}> */
    private array $pages = [];

    public function __construct(private readonly string $pdf)
    {
        preg_match_all(
            '/(\d+) 0 obj\r?\n(.*?)\r?\nendobj/s',
            $pdf,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $this->objects[(int) $match[1]] = $match[2];
        }

        foreach ($this->objects as $number => $object) {
            if (! preg_match('/\/Type \/Page\b/', $object)) {
                continue;
            }

            if (! preg_match('/\/MediaBox \[\s*[-\d.]+\s+[-\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $object, $box)
                || ! preg_match('/\/Contents (\d+) 0 R/', $object, $content)
                || ! preg_match('/\/Rotate (-?\d+)/', $object, $rotation)) {
                throw new RuntimeException('A rendered PDF page could not be inspected.');
            }

            $this->pages[] = [
                'object' => $number,
                'width' => (float) $box[1],
                'height' => (float) $box[2],
                'rotation' => (int) $rotation[1],
                'content' => (int) $content[1],
            ];
        }
    }

    /** @return list<array{object: int, width: float, height: float, rotation: int, content: int}> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function pageContent(int $page): string
    {
        $pageData = $this->pages[$page - 1] ?? null;
        if ($pageData === null) {
            throw new RuntimeException('The rendered PDF page does not exist.');
        }

        return $this->stream($pageData['content']);
    }

    /** @return list<array{x: float, y: float, width: float, height: float}> */
    public function clippingRectangles(int $page): array
    {
        preg_match_all(
            '/q\s+(-?[\d.]+)\s+(-?[\d.]+)\s+([\d.]+)\s+([\d.]+)\s+re\s+W\s+n/',
            $this->pageContent($page),
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(static fn (array $match): array => [
            'x' => (float) $match[1],
            'y' => (float) $match[2],
            'width' => (float) $match[3],
            'height' => (float) $match[4],
        ], $matches);
    }

    /** @return list<array{x: float, y: float, width: float, height: float}> */
    public function imageRectangles(int $page): array
    {
        preg_match_all(
            '/q\s+([\d.]+)\s+0\s+0\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+cm\s+\/IMG(?:plain|mask)?\d+\s+Do\s+Q/',
            $this->pageContent($page),
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(static fn (array $match): array => [
            'x' => (float) $match[3],
            'y' => (float) $match[4],
            'width' => (float) $match[1],
            'height' => (float) $match[2],
        ], $matches);
    }

    /** @return array{dictionary: string, content: string} */
    public function sourceForm(int $page): array
    {
        if (! preg_match('/\/(IMP\d+)\s+Do/', $this->pageContent($page), $name)
            || ! preg_match('/\/'.$name[1].'\s+(\d+)\s+0\s+R/', $this->pdf, $reference)) {
            throw new RuntimeException('The imported source form could not be inspected.');
        }

        $object = $this->objects[(int) $reference[1]] ?? null;
        if ($object === null) {
            throw new RuntimeException('The imported source form object does not exist.');
        }

        return [
            'dictionary' => strstr($object, "\nstream", true) ?: $object,
            'content' => $this->decodeStream($object),
        ];
    }

    /** @return array{x: float, y: float} */
    public function sourceTextOriginOnPage(int $page, string $text): array
    {
        $form = $this->sourceForm($page);
        $number = '(-?[\d.]+)';
        if (! preg_match(
            '/'.$number.'\s+'.$number.'\s+Td\s+\('.preg_quote($text, '/').'\)\s+Tj/',
            $form['content'],
            $origin,
        )) {
            throw new RuntimeException('The imported source text origin could not be inspected.');
        }

        return $this->sourcePointOnPage($page, (float) $origin[1], (float) $origin[2]);
    }

    /** @return list<array{x: float, y: float}> */
    public function sourceFiducialCentersOnPage(int $page): array
    {
        $number = '(-?[\d.]+)';
        preg_match_all(
            '/q\s+(?:1 0 0|0 1 0|0 0 1|1 0 1|1 0\.5 0)\s+rg\s+'.$number.'\s+'.$number.'\s+6(?:\.0+)?\s+6(?:\.0+)?\s+re\s+f\s+Q/',
            $this->sourceForm($page)['content'],
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            fn (array $match): array => $this->sourcePointOnPage(
                $page,
                (float) $match[1] + 3,
                (float) $match[2] + 3,
            ),
            $matches,
        );
    }

    /** @return list<array{font: string, text: string}> */
    public function textRuns(int $page): array
    {
        $pageData = $this->pages[$page - 1] ?? null;
        if ($pageData === null) {
            throw new RuntimeException('The rendered PDF page does not exist.');
        }

        $pageObject = $this->objects[$pageData['object']];
        $resourceObject = $pageObject;
        if (preg_match('/\/Resources (\d+) 0 R/', $pageObject, $resourceReference)) {
            $resourceObject = $this->objects[(int) $resourceReference[1]] ?? '';
        }

        preg_match_all('/\/(F\d+)\s+(\d+)\s+0\s+R/', $resourceObject, $references, PREG_SET_ORDER);
        $fonts = [];
        foreach ($references as $reference) {
            $fontObject = $this->objects[(int) $reference[2]] ?? '';
            if (! preg_match('/\/BaseFont \/([^\s\/]+)/', $fontObject, $baseFont)
                || ! preg_match('/\/ToUnicode (\d+) 0 R/', $fontObject, $unicodeReference)) {
                continue;
            }
            $fonts[$reference[1]] = [
                'name' => preg_replace('/^[A-Z]{6}\+/', '', $baseFont[1]) ?? $baseFont[1],
                'map' => $this->unicodeMap((int) $unicodeReference[1]),
            ];
        }

        $decoded = [];
        $content = $this->pageContent($page);
        foreach ($this->literalStringsWithOffsets($content) as $literal) {
            preg_match_all(
                '/\/(F\d+)\s+[\d.]+\s+Tf\b/',
                substr($content, 0, $literal['offset']),
                $operators,
                PREG_SET_ORDER,
            );
            $operator = end($operators);
            $font = is_array($operator) ? ($fonts[$operator[1]] ?? null) : null;
            if ($font === null) {
                continue;
            }
            $text = $this->decodeCompositeText($this->decodeLiteral($literal['literal']), $font['map']);
            if ($text !== null) {
                $decoded[] = ['font' => $font['name'], 'text' => $text];
            }
        }

        return $decoded;
    }

    /** @return array{x: float, y: float} */
    private function sourcePointOnPage(int $page, float $sourceX, float $sourceY): array
    {
        $number = '(-?[\d.]+)';
        if (! preg_match(
            '/q\s+'.$number.'\s+'.$number.'\s+'.$number.'\s+'.$number.'\s+'.$number.'\s+'.$number.'\s+cm\s+\/IMP\d+\s+Do\s+Q/',
            $this->pageContent($page),
            $placement,
        )) {
            throw new RuntimeException('The imported source placement matrix could not be inspected.');
        }

        $form = $this->sourceForm($page);
        if (! preg_match(
            '/\/Matrix \[\s*'.$number.'\s+'.$number.'\s+'.$number.'\s+'.$number.'\s+'.$number.'\s+'.$number.'\s*\]/',
            $form['dictionary'],
            $matrix,
        )) {
            throw new RuntimeException('The imported source matrix could not be inspected.');
        }

        $x = ((float) $matrix[1] * $sourceX) + ((float) $matrix[3] * $sourceY) + (float) $matrix[5];
        $y = ((float) $matrix[2] * $sourceX) + ((float) $matrix[4] * $sourceY) + (float) $matrix[6];

        return [
            'x' => ((float) $placement[1] * $x) + ((float) $placement[3] * $y) + (float) $placement[5],
            'y' => ((float) $placement[2] * $x) + ((float) $placement[4] * $y) + (float) $placement[6],
        ];
    }

    /** @return array<string, string|false> */
    private function unicodeMap(int $object): array
    {
        $stream = $this->stream($object);
        preg_match_all('/<([0-9a-f]+)>\s+<([0-9a-f]+)>/i', $stream, $characters, PREG_SET_ORDER);
        $map = [];
        foreach ($characters as $character) {
            $map[strtoupper($character[1])] = hex2bin($character[2]);
        }

        return $map;
    }

    public function renderedText(?int $page = null): string
    {
        $maps = [];
        foreach ($this->objects as $object) {
            $stream = $this->decodeStream($object);
            if (! str_contains($stream, 'begincmap')) {
                continue;
            }

            preg_match_all('/<([0-9a-f]+)>\s+<([0-9a-f]+)>/i', $stream, $characters, PREG_SET_ORDER);
            $map = [];
            foreach ($characters as $character) {
                $map[strtoupper($character[1])] = hex2bin($character[2]);
            }
            $maps[] = $map;
        }

        $text = [];
        $contentStreams = $page === null
            ? array_map($this->decodeStream(...), $this->objects)
            : [$this->pageContent($page)];
        foreach ($contentStreams as $stream) {
            if (str_contains($stream, 'begincmap')) {
                continue;
            }

            foreach ($this->literalStrings($stream) as $literal) {
                $bytes = $this->decodeLiteral($literal);
                foreach ($maps as $map) {
                    $decoded = $this->decodeCompositeText($bytes, $map);
                    if ($decoded !== null) {
                        $text[] = $decoded;
                    }
                }
            }
        }

        return implode("\n", $text);
    }

    /** @return list<string> */
    private function literalStrings(string $stream): array
    {
        return array_column($this->literalStringsWithOffsets($stream), 'literal');
    }

    /** @return list<array{literal: string, offset: int}> */
    private function literalStringsWithOffsets(string $stream): array
    {
        $strings = [];
        $offset = 0;
        while (($end = strpos($stream, ') Tj', $offset)) !== false) {
            for ($start = $end - 1; $start >= 0; $start--) {
                if ($stream[$start] !== '(') {
                    continue;
                }

                $slashes = 0;
                for ($index = $start - 1; $index >= 0 && $stream[$index] === '\\'; $index--) {
                    $slashes++;
                }
                if ($slashes % 2 !== 0) {
                    continue;
                }

                $strings[] = [
                    'literal' => substr($stream, $start + 1, $end - $start - 1),
                    'offset' => $start,
                ];
                break;
            }
            $offset = $end + strlen(') Tj');
        }

        return $strings;
    }

    private function stream(int $object): string
    {
        $data = $this->objects[$object] ?? null;
        if ($data === null) {
            throw new RuntimeException('The rendered PDF stream does not exist.');
        }

        return $this->decodeStream($data);
    }

    private function decodeStream(string $object): string
    {
        if (! preg_match('/\bstream\r?\n/', $object, $startMatch, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $startMatch[0][1] + strlen($startMatch[0][0]);
        $dictionary = substr($object, 0, $start);
        if (preg_match('/\/Length\s+(\d+)\b/', $dictionary, $lengthMatch)) {
            $stream = substr($object, $start, (int) $lengthMatch[1]);
        } else {
            if (! preg_match('/\r?\nendstream/', $object, $endMatch, PREG_OFFSET_CAPTURE, $start)) {
                throw new RuntimeException('The rendered PDF stream is incomplete.');
            }
            $stream = substr($object, $start, $endMatch[0][1] - $start);
        }
        if (! str_contains($dictionary, '/FlateDecode')) {
            return $stream;
        }

        $decoded = @gzuncompress($stream);
        if (! is_string($decoded)) {
            throw new RuntimeException('The rendered PDF stream could not be decoded.');
        }

        return $decoded;
    }

    private function decodeLiteral(string $literal): string
    {
        $decoded = '';
        $length = strlen($literal);
        for ($index = 0; $index < $length; $index++) {
            if ($literal[$index] !== '\\') {
                $decoded .= $literal[$index];

                continue;
            }

            $index++;
            if ($index >= $length) {
                break;
            }

            $escaped = $literal[$index];
            if ($escaped === "\r" || $escaped === "\n") {
                if ($escaped === "\r" && ($literal[$index + 1] ?? null) === "\n") {
                    $index++;
                }

                continue;
            }

            if ($escaped >= '0' && $escaped <= '7') {
                $octal = $escaped;
                while (strlen($octal) < 3 && ($literal[$index + 1] ?? '8') >= '0' && ($literal[$index + 1] ?? '8') <= '7') {
                    $octal .= $literal[++$index];
                }
                $decoded .= chr(octdec($octal));

                continue;
            }

            $decoded .= match ($escaped) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'b' => "\x08",
                'f' => "\x0c",
                default => $escaped,
            };
        }

        return $decoded;
    }

    /** @param array<string, string|false> $map */
    private function decodeCompositeText(string $bytes, array $map): ?string
    {
        if (strlen($bytes) % 2 !== 0) {
            return null;
        }

        $unicode = '';
        for ($offset = 0; $offset < strlen($bytes); $offset += 2) {
            $character = $map[strtoupper(bin2hex(substr($bytes, $offset, 2)))] ?? null;
            if (! is_string($character)) {
                return null;
            }
            $unicode .= $character;
        }

        return mb_convert_encoding($unicode, 'UTF-8', 'UTF-16BE');
    }
}
