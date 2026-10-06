<?php

use App\Services\PdfDocumentService;
use ArtisanBuild\BuiltForCloud\SystemAuthoritySchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$schedule = resolve('Illuminate\Console\Scheduling\Schedule');

resolve(SystemAuthoritySchedule::class)->call($schedule, fn () => resolve(PdfDocumentService::class)->retryPending())
    ->name('witness:cleanup-private-files')
    ->everyMinute()
    ->withoutOverlapping();
