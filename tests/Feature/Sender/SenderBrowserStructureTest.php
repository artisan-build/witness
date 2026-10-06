<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Models\Team;
use App\Models\Template;
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
    $template = Template::factory()->forOwner($team, $user)->create([
        'storage_path' => 'documents/'.$team->getKey().'/template-browser-fixture.pdf',
        'page_count' => 2,
    ]);
    $role = $template->roles()->create(['label' => 'Client', 'position' => 0]);
    $role->fields()->create(['page' => 2, 'type' => 'signature', 'required' => true, 'position_x' => .17, 'position_y' => .23, 'width' => .31, 'height' => .09]);

    $this->actingAsVersioned($user)->withSession(['witness.team_id' => $team->getKey()])
        ->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertSee('data-testid="field-placement"', false)
        ->assertSee('data-testid="pdf-page-canvas"', false)
        ->assertSee('data-page-next', false)
        ->assertSee('data-field-type="signature"', false)
        ->assertSee('0.17')
        ->assertSee('0.23')
        ->assertSee('\\u003Cscript\\u003Eunsafe', false)
        ->assertDontSee('<script>unsafe</script>', false)
        ->assertSee('wire:name="sender.envelope-editor"', false)
        ->assertSee('x-data="witnessPlacement', false)
        ->assertSee('data-flux-button', false)
        ->assertSee('build/assets/sender.js');

    $this->get(route('sender.templates.edit', $template))
        ->assertOk()
        ->assertSee('wire:name="sender.template-editor"', false)
        ->assertSee('data-testid="template-field-placement"', false)
        ->assertSee('x-data="witnessPlacement', false)
        ->assertSee('data-flux-button', false);

    $senderScript = (string) file_get_contents(public_path('build/assets/sender.js'));
    $canvasWidth = strpos($senderScript, 'this.$refs.canvas.width = viewport.width;');
    $canvasHeight = strpos($senderScript, 'this.$refs.canvas.height = viewport.height;');
    $render = strpos($senderScript, 'const renderTask = page.render({ canvasContext: context, viewport });');

    expect(file_exists(public_path('build/assets/pdf.min.mjs')))->toBeTrue()
        ->and(file_exists(public_path('build/assets/pdf.worker.min.mjs')))->toBeTrue()
        ->and(file_exists(public_path('build/assets/pdfjs-LICENSE.txt')))->toBeTrue()
        ->and($senderScript)->toContain("import * as pdfjsLib from './pdf.min.mjs'")
        ->and($senderScript)->toContain('ResizeObserver')
        ->and($senderScript)->toContain("window.Alpine.data('witnessPlacement'")
        ->and($senderScript)->toContain('const pdfDocuments = new WeakMap();')
        ->and($senderScript)->toContain('pdfDocuments.set(this, pdfDocument);')
        ->and($senderScript)->not->toContain('this.pdf')
        ->and($senderScript)->toContain('const page = await pdfDocument.getPage(this.currentPage);')
        ->and($senderScript)->toContain('const renderTask = page.render({ canvasContext: context, viewport });')
        ->and($canvasWidth)->toBeInt()
        ->and($canvasHeight)->toBeInt()
        ->and($render)->toBeInt();

    assert(is_int($canvasWidth) && is_int($canvasHeight) && is_int($render));
    expect($canvasWidth)->toBeLessThan($render)
        ->and($canvasHeight)->toBeLessThan($render);
});
