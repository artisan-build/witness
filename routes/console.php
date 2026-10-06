<?php

use App\Services\PdfDocumentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => resolve(PdfDocumentService::class)->retryPending())
    ->name('witness:cleanup-private-files')
    ->everyMinute()
    ->withoutOverlapping();
