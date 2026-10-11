<?php

declare(strict_types=1);

use App\Console\Commands\PreparePdfFonts;
use App\Services\PdfFontCoverage;
use Illuminate\Support\Facades\Artisan;

it('generates the pinned licensed fonts and keeps every source pinned to its committed bytes', function (): void {
    $pins = (new ReflectionClass(PreparePdfFonts::class))->getConstant('SOURCES');
    expect($pins)->toBe([
        'Pacifico-Regular.ttf' => '5b6c0d5334a7bf77dea52b975c5a0c408878c0f7115ed5b6fb151f634b7bf701',
        'NotoSans.ttf' => 'bfb7bb691513f12e734dc346c03a03f784912432d7e3fa8e56efcf906fe86b3d',
    ]);

    foreach ($pins as $filename => $expectedHash) {
        $source = resource_path('fonts/source').DIRECTORY_SEPARATOR.$filename;
        expect(is_file($source))->toBeTrue()
            ->and(hash_equals($expectedHash, (string) hash_file('sha256', $source)))->toBeTrue();
    }

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
