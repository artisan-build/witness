<?php

use App\Services\EnvelopeCompletionService;
use App\Services\OutboundMailDelivery;
use App\Services\PdfDocumentService;
use ArtisanBuild\BuiltForCloud\SystemAuthoritySchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

resolve(SystemAuthoritySchedule::class)->call(Schedule::getFacadeRoot(), function (): void {
    resolve(PdfDocumentService::class)->retryPending();
    resolve(OutboundMailDelivery::class)->reconcileStaleClaims();
    resolve(EnvelopeCompletionService::class)->dispatchReady();
})
    ->name('witness:cleanup-private-files')
    ->everyMinute()
    ->withoutOverlapping();
