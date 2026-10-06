<?php

declare(strict_types=1);

namespace App\Services;

use Com\Tecnick\Pdf\Tcpdf;
use RuntimeException;

final class PdfFontCoverage
{
    public const string SCRIPT = 'pacifico';

    public const string TEXT = 'notosans';

    /** @var array<string, Tcpdf> */
    private array $fonts = [];

    public function supports(string $font, string $text): bool
    {
        $pdf = $this->font($font);

        foreach (mb_str_split($text) as $character) {
            if (in_array($character, ["\n", "\r", "\t"], true)) {
                continue;
            }

            $codepoint = mb_ord($character);
            if (! $pdf->font->isCharDefined($codepoint) || $pdf->font->getGidForOrd($codepoint) === 0) {
                return false;
            }
        }

        return true;
    }

    public function assertSupported(string $font, string $text): void
    {
        if (! $this->supports($font, $text)) {
            throw new RuntimeException('The completed document contains text unsupported by its PDF font.');
        }
    }

    private function font(string $font): Tcpdf
    {
        if (! in_array($font, [self::SCRIPT, self::TEXT], true)) {
            throw new RuntimeException('An unsupported PDF font was requested.');
        }

        if (isset($this->fonts[$font])) {
            return $this->fonts[$font];
        }

        $directory = resource_path('fonts/generated');
        if (! is_file($directory.DIRECTORY_SEPARATOR.$font.'.json')) {
            throw new RuntimeException('Required PDF fonts have not been generated.');
        }
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $directory);
        }

        $pdf = new Tcpdf(unit: 'pt', isunicode: true, subsetfont: true, compress: true);
        $pdf->font->insert($pdf->pon, $font, '', 12);

        return $this->fonts[$font] = $pdf;
    }
}
