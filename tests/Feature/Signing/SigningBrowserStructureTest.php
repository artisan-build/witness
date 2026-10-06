<?php

declare(strict_types=1);

use App\Actions\SendEnvelope;
use App\Enums\FieldType;
use App\Http\Controllers\SigningController;
use App\Mail\SigningInvitation;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUiAuthority;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RealTextPdfFixture;

it('ships the complete local signer browser structure and real-text fixture', function (): void {
    $view = file_get_contents(resource_path('views/sign/document.blade.php'));
    $script = file_get_contents(public_path('build/assets/signing.js'));
    $styles = file_get_contents(public_path('build/assets/signing.css'));
    $fixture = RealTextPdfFixture::make(2);

    expect($view)->toContain('data-testid="signer-pdf-viewer"')
        ->toContain('data-testid="signer-fields"')
        ->toContain('data-testid="signer-field"')
        ->toContain('data-testid="signer-field-overlays"')
        ->toContain('data-testid="signer-field-overlay"')
        ->toContain('data-field-page=')
        ->toContain('tabindex="-1"')
        ->toContain('data-testid="signer-intent"')
        ->toContain('data-testid="signer-decline"')
        ->toContain('data-signature-canvas')
        ->and($script)->toContain("import * as pdfjsLib from './pdf.min.mjs'")
        ->toContain('isEvalSupported: false')
        ->toContain('stage.style.width = `${viewport.width}px`')
        ->toContain("fieldset.addEventListener('focusin'")
        ->toContain("fieldset?.querySelector('input, textarea, button') ?? fieldset")
        ->toContain("scrollIntoView({ block: 'nearest', inline: 'nearest' })")
        ->toContain('focusedFieldId')
        ->toContain("canvas.toDataURL('image/png')")
        ->and($styles)->toContain('.signing-script')
        ->toContain('.signing-draw canvas')
        ->and($fixture)->toContain('Witness signing fixture - page 1')
        ->toContain('Witness signing fixture - page 2')
        ->toContain('Visible agreement text for browser verification.')
        ->and(file_exists(public_path('build/assets/pdf.min.mjs')))->toBeTrue()
        ->and(file_exists(public_path('build/assets/pdf.worker.min.mjs')))->toBeTrue();
});

it('renders only the active signer fields as page-relative overlays', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature, FieldType::Text, FieldType::DateSigned], false);
    $otherSigner = $envelope->signers()->create([
        'name' => 'Other Signer',
        'email' => 'other@example.test',
        'position' => 1,
    ]);
    $otherField = $otherSigner->fields()->create([
        'page' => 2,
        'type' => FieldType::Signature,
        'required' => true,
        'position_x' => .71,
        'position_y' => .62,
        'width' => .07,
        'height' => .06,
    ]);
    resolve(SendEnvelope::class)->handle($envelope->team, $envelope->sender, $envelope->getKey());

    $token = null;
    Mail::assertSent(SigningInvitation::class, function (SigningInvitation $mail) use ($signer, &$token): bool {
        if ($mail->signer->is($signer)) {
            $token = $mail->linkToken;
        }

        return $mail->signer->is($signer);
    });
    expect($token)->toBeString();
    assert(is_string($token));
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect(route('sign.show'));

    $response = $this->get(route('sign.show'))->assertOk();
    foreach ($signer->fields as $field) {
        $response->assertSee('data-testid="signer-field-overlay"', false)
            ->assertSee('data-field-id="'.$field->getKey().'"', false)
            ->assertSee('data-field-page="'.$field->page.'"', false)
            ->assertSee('id="signer-field-'.$field->getKey().'"', false);
    }
    $response->assertDontSee('data-field-id="'.$otherField->getKey().'"', false)
        ->assertDontSee('Other Signer');

    $dateField = $signer->fields->firstWhere('type', FieldType::DateSigned);
    expect($dateField)->not->toBeNull();
    $document = new DOMDocument;
    @$document->loadHTML((string) $response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//fieldset[@id="signer-field-'.$dateField?->getKey().'" and @tabindex="-1"]')->length)->toBe(1);
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
