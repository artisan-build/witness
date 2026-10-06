<?php

use App\Console\Commands\OptimizeTailwind;
use App\Console\Commands\PreparePdfFonts;
use App\Http\Middleware\PreventVerificationCaching;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        OptimizeTailwind::class,
        PreparePdfFonts::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(ThrottleRequests::class, PreventVerificationCaching::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'code',
            'otp',
            'token',
            'fields',
            'signature',
            'initials',
            'drawn',
            'reason',
            'verification_document',
        ]);
    })->create();
