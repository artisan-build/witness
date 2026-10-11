<?php

declare(strict_types=1);

use App\Console\Commands\PreparePdfFonts;
use App\Services\PdfFontCoverage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

it('generates the pinned licensed fonts and keeps every source pinned to its committed bytes', function (): void {
    $pins = (new ReflectionClass(PreparePdfFonts::class))->getConstant('SOURCES');
    $files = resolve(Filesystem::class);

    foreach ($pins as $filename => $expectedHash) {
        $source = resource_path('fonts/source').DIRECTORY_SEPARATOR.$filename;
        expect(is_file($source))->toBeTrue()
            ->and(hash_equals($expectedHash, (string) hash_file('sha256', $source)))->toBeTrue();
    }

    $files->cleanDirectory(resource_path('fonts/generated'));
    expect(Artisan::call('witness:prepare-pdf-fonts'))->toBe(0);

    $coverage = resolve(PdfFontCoverage::class);
    foreach ([PdfFontCoverage::SCRIPT, PdfFontCoverage::TEXT] as $font) {
        expect(is_file(resource_path('fonts/generated').DIRECTORY_SEPARATOR.$font.'.json'))->toBeTrue()
            ->and($coverage->supports($font, 'Witness Aa Bb 42'))->toBeTrue();
    }
});

it('refuses font names outside the licensed set', function (): void {
    $coverage = resolve(PdfFontCoverage::class);

    expect(fn () => $coverage->supports('comic-sans', 'Witness'))->toThrow(RuntimeException::class, 'unsupported PDF font');
});
