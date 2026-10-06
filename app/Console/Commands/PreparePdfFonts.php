<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Com\Tecnick\Pdf\Font\Import;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class PreparePdfFonts extends Command
{
    /** @var string */
    protected $signature = 'witness:prepare-pdf-fonts';

    /** @var string */
    protected $description = 'Generate the licensed PDF fonts used by Witness';

    /** @var array<string, string> */
    private const array SOURCES = [
        'Pacifico-Regular.ttf' => '5b6c0d5334a7bf77dea52b975c5a0c408878c0f7115ed5b6fb151f634b7bf701',
        'NotoSans.ttf' => 'bfb7bb691513f12e734dc346c03a03f784912432d7e3fa8e56efcf906fe86b3d',
    ];

    public function handle(Filesystem $files): int
    {
        $sourceDirectory = resource_path('fonts/source');
        $outputDirectory = resource_path('fonts/generated');

        $files->ensureDirectoryExists($outputDirectory);
        $files->cleanDirectory($outputDirectory);

        foreach (self::SOURCES as $filename => $expectedHash) {
            $source = $sourceDirectory.DIRECTORY_SEPARATOR.$filename;

            if (! $files->isFile($source) || ! hash_equals($expectedHash, hash_file('sha256', $source))) {
                throw new RuntimeException("PDF font source {$filename} is missing or has changed.");
            }

            $font = new Import($source, $outputDirectory);
            $definition = $outputDirectory.DIRECTORY_SEPARATOR.$font->getFontName().'.json';

            if (! $files->isFile($definition)) {
                throw new RuntimeException("PDF font {$filename} was not generated.");
            }

            $this->components->info("Generated {$font->getFontName()}.");
        }

        return self::SUCCESS;
    }
}
