<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Models\Team;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

it('renders the real PDF placement structure with stable browser hooks and committed assets', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user);
    $pdf = PdfFixture::make(2);
    $path = 'documents/'.$team->getKey().'/browser-fixture.pdf';
    Storage::put($path, $pdf, ['visibility' => 'private']);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        $team,
        $user,
        'Browser fixture',
        'browser-fixture.pdf',
        $path,
        strlen($pdf),
        2,
        hash('sha256', $pdf),
    );
    $signer = $envelope->signers()->create(['name' => '<script>unsafe</script>', 'email' => 'browser@example.test']);
    $signer->fields()->create(['page' => 2, 'type' => 'signature', 'required' => true, 'position_x' => .17, 'position_y' => .23, 'width' => .31, 'height' => .09]);

    $this->actingAsVersioned($user)->withSession(['witness.team_id' => $team->getKey()])
        ->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertSee('data-testid="field-placement"', false)
        ->assertSee('data-testid="pdf-page-canvas"', false)
        ->assertSee('data-page-next', false)
        ->assertSee('data-field-type="signature"', false)
        ->assertSee('0.17')
        ->assertSee('0.23')
        ->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)
        ->assertSee('build/assets/sender.js');

    expect(file_exists(public_path('build/assets/pdf.min.mjs')))->toBeTrue()
        ->and(file_exists(public_path('build/assets/pdf.worker.min.mjs')))->toBeTrue()
        ->and(file_exists(public_path('build/assets/pdfjs-LICENSE.txt')))->toBeTrue()
        ->and(file_get_contents(public_path('build/assets/sender.js')))->toContain("import * as pdfjsLib from './pdf.min.mjs'")
        ->and(file_get_contents(public_path('build/assets/sender.js')))->toContain('ResizeObserver');
});
