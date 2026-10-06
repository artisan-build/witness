<?php

declare(strict_types=1);

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EnvelopeController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SigningController;
use App\Http\Controllers\TeamSelectionController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\AddSigningSecurityHeaders;
use App\Http\Middleware\EnsureSelectedTeam;
use App\Http\Middleware\PreventVerificationCaching;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUiAuthority;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAdmin;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Illuminate\Support\Facades\Route;

Route::middleware([AddSigningSecurityHeaders::class, 'throttle:signing-public'])->group(function (): void {
    Route::get('/sign', [SigningController::class, 'show'])->name('sign.show');
    Route::post('/sign/code', [SigningController::class, 'verify'])->name('sign.verify');
    Route::post('/sign/code/rotate', [SigningController::class, 'rotateCode'])->name('sign.code.rotate');
    Route::post('/sign/consent', [SigningController::class, 'consent'])->name('sign.consent');
    Route::get('/sign/document', [SigningController::class, 'document'])->name('sign.document');
    Route::post('/sign/fields', [SigningController::class, 'saveFields'])->name('sign.fields');
    Route::post('/sign/complete', [SigningController::class, 'sign'])->name('sign.complete');
    Route::post('/sign/decline', [SigningController::class, 'decline'])->name('sign.decline');
    Route::get('/sign/{token}', [SigningController::class, 'open'])->name('sign.open');
});

Route::middleware([PreventVerificationCaching::class, AddSigningSecurityHeaders::class, 'throttle:verification-public'])->group(function (): void {
    Route::get('/verify', [VerificationController::class, 'index'])->name('verify.index');
    Route::post('/verify', [VerificationController::class, 'store'])->name('verify.store');
});

Route::middleware([AddSigningSecurityHeaders::class, 'throttle:verification-public'])->group(function (): void {
    Route::get('/final/{delivery}', [DocumentController::class, 'recipient'])
        ->middleware('signed')
        ->name('final.recipient.download');
});

Route::middleware([EnsureUiAuthority::class, EnsureUserIsAuthenticated::class])->group(function (): void {
    Route::middleware(EnsureUserIsAdmin::class)->group(function (): void {
        Route::get('/setup', [SetupController::class, 'index'])->name('sender.setup');
        Route::post('/setup/test-mail', [SetupController::class, 'sendTest'])->name('sender.setup.test-mail');
    });
    Route::get('/teams/select', [TeamSelectionController::class, 'index'])->name('sender.teams.select');
    Route::post('/teams/select', [TeamSelectionController::class, 'store'])->name('sender.teams.store');

    Route::middleware(EnsureSelectedTeam::class)->group(function (): void {
        Route::get('/app', [EnvelopeController::class, 'index'])->name('sender.dashboard');
        Route::get('/envelopes/new', [EnvelopeController::class, 'create'])->name('sender.envelopes.create');
        Route::post('/envelopes', [EnvelopeController::class, 'store'])->name('sender.envelopes.store');
        Route::get('/envelopes/{envelope}', [EnvelopeController::class, 'edit'])->name('sender.envelopes.edit');
        Route::put('/envelopes/{envelope}', [EnvelopeController::class, 'update'])->name('sender.envelopes.update');
        Route::put('/envelopes/{envelope}/document', [EnvelopeController::class, 'replaceDocument'])->name('sender.envelopes.document');
        Route::put('/envelopes/{envelope}/signers', [EnvelopeController::class, 'updateSigners'])->name('sender.envelopes.signers');
        Route::put('/envelopes/{envelope}/fields', [EnvelopeController::class, 'updateFields'])->name('sender.envelopes.fields');
        Route::post('/envelopes/{envelope}/send', [EnvelopeController::class, 'send'])->name('sender.envelopes.send');
        Route::post('/envelopes/{envelope}/signers/{signer}/resend-link', [EnvelopeController::class, 'resendLink'])->name('sender.envelopes.signers.resend-link');
        Route::post('/envelopes/{envelope}/void', [EnvelopeController::class, 'void'])->name('sender.envelopes.void');
        Route::post('/envelopes/{envelope}/retry-completion', [EnvelopeController::class, 'retryCompletion'])->name('sender.envelopes.retry-completion');
        Route::post('/envelopes/{envelope}/deliveries/{delivery}/resend', [EnvelopeController::class, 'resendUnknown'])->name('sender.envelopes.deliveries.resend');
        Route::get('/envelopes/{envelope}/delete', [EnvelopeController::class, 'confirmDelete'])->name('sender.envelopes.delete');
        Route::delete('/envelopes/{envelope}', [EnvelopeController::class, 'destroy'])->name('sender.envelopes.destroy');
        Route::post('/envelopes/{envelope}/template', [EnvelopeController::class, 'saveAsTemplate'])->name('sender.envelopes.template');
        Route::get('/envelopes/{envelope}/document', [DocumentController::class, 'envelope'])->name('sender.envelopes.document.show');
        Route::get('/envelopes/{envelope}/final-document', [DocumentController::class, 'final'])->name('sender.envelopes.final-document');

        Route::get('/templates', [TemplateController::class, 'index'])->name('sender.templates.index');
        Route::get('/templates/new', [TemplateController::class, 'create'])->name('sender.templates.create');
        Route::post('/templates', [TemplateController::class, 'store'])->name('sender.templates.store');
        Route::get('/templates/{template}', [TemplateController::class, 'edit'])->name('sender.templates.edit');
        Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('sender.templates.update');
        Route::put('/templates/{template}/document', [TemplateController::class, 'replaceDocument'])->name('sender.templates.document.update');
        Route::put('/templates/{template}/roles', [TemplateController::class, 'updateRoles'])->name('sender.templates.roles');
        Route::put('/templates/{template}/fields', [TemplateController::class, 'updateFields'])->name('sender.templates.fields');
        Route::get('/templates/{template}/send', [TemplateController::class, 'sendForm'])->name('sender.templates.send-form');
        Route::post('/templates/{template}/send', [TemplateController::class, 'instantiate'])->name('sender.templates.send');
        Route::get('/templates/{template}/document', [DocumentController::class, 'template'])->name('sender.templates.document');
    });
});
