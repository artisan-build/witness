<?php

declare(strict_types=1);

use App\Http\Controllers\SigningController;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUiAuthority;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Illuminate\Routing\Route;
use Tests\Support\RealTextPdfFixture;

it('ships the complete local signer browser structure and real-text fixture', function (): void {
    $view = file_get_contents(resource_path('views/sign/document.blade.php'));
    $script = file_get_contents(public_path('build/assets/signing.js'));
    $styles = file_get_contents(public_path('build/assets/signing.css'));
    $fixture = RealTextPdfFixture::make(2);

    expect($view)->toContain('data-testid="signer-pdf-viewer"')
        ->toContain('data-testid="signer-fields"')
        ->toContain('data-testid="signer-field"')
        ->toContain('data-testid="signer-intent"')
        ->toContain('data-testid="signer-decline"')
        ->toContain('data-signature-canvas')
        ->and($script)->toContain("import * as pdfjsLib from './pdf.min.mjs'")
        ->toContain('isEvalSupported: false')
        ->toContain("canvas.toDataURL('image/png')")
        ->and($styles)->toContain('.signing-script')
        ->toContain('.signing-draw canvas')
        ->and($fixture)->toContain('Witness signing fixture - page 1')
        ->toContain('Witness signing fixture - page 2')
        ->toContain('Visible agreement text for browser verification.')
        ->and(file_exists(public_path('build/assets/pdf.min.mjs')))->toBeTrue()
        ->and(file_exists(public_path('build/assets/pdf.worker.min.mjs')))->toBeTrue();
});

it('keeps every signer route outside human authentication middleware', function (): void {
    $routes = collect(resolve('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => $route->getControllerClass() === SigningController::class);

    expect($routes)->toHaveCount(9);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('throttle:signing-public')
            ->not->toContain(EnsureUserIsAuthenticated::class)
            ->not->toContain(EnsureUiAuthority::class);
    }
});
