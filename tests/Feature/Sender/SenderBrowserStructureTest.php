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
    $signer->fields()->create(['page' => 2, 'type' => 'signature', 'required' => true, 'position_x' => .173456, 'position_y' => .234567, 'width' => .314159, 'height' => .092857]);
    $template = Template::factory()->forOwner($team, $user)->create([
        'storage_path' => 'documents/'.$team->getKey().'/template-browser-fixture.pdf',
        'page_count' => 2,
    ]);
    $role = $template->roles()->create(['label' => 'Client', 'position' => 0]);
    $role->fields()->create(['page' => 2, 'type' => 'signature', 'required' => true, 'position_x' => .173456, 'position_y' => .234567, 'width' => .314159, 'height' => .092857]);
    $template->roles()->create(['label' => '<script>unsafe-role</script>', 'position' => 1]);

    $envelopeResponse = $this->actingAsVersioned($user)->withSession(['witness.team_id' => $team->getKey()])
        ->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertSee('data-testid="field-placement"', false)
        ->assertSee('data-testid="pdf-page-canvas"', false)
        ->assertSee('data-page-next', false)
        ->assertSee('data-field-type="signature"', false)
        ->assertSee('\\u003Cscript\\u003Eunsafe', false)
        ->assertDontSee('<script>unsafe</script>', false)
        ->assertSee('wire:name="sender.envelope-editor"', false)
        ->assertSee('x-data="witnessPlacement', false)
        ->assertSee('data-flux-button', false)
        ->assertSee('2 pages')
        ->assertSee('build/assets/sender.js');

    $document = new DOMDocument;
    @$document->loadHTML((string) $envelopeResponse->getContent());
    $xpath = new DOMXPath($document);
    foreach (['Replace', 'Add signer', 'Save signers', 'Save as template'] as $label) {
        expect($xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " witness-button ") and normalize-space(.)="'.$label.'"]')->length)->toBe(1);
    }

    $islands = $xpath->query('//script[@type="application/json" and @data-placement-data]');
    expect($islands->length)->toBe(1)
        ->and($islands->item(0)->textContent)->toContain('0.173456')
        ->toContain('0.234567')
        ->toContain('0.314159')
        ->toContain('0.092857')
        ->toContain('\u003Cscript\u003Eunsafe');

    $envelopeResponse->assertSee('<button class="witness-button" type="submit">Save details</button>', false)
        ->assertSee('<button class="witness-button" type="submit" data-testid="save-fields">Save placements</button>', false)
        ->assertSee('<button class="witness-button witness-button-full" type="submit" data-testid="send-envelope">Send and freeze</button>', false)
        ->assertSee('<input class="witness-file-input" type="file"', false);

    $templateResponse = $this->get(route('sender.templates.edit', $template))
        ->assertOk()
        ->assertSee('wire:name="sender.template-editor"', false)
        ->assertSee('data-testid="template-field-placement"', false)
        ->assertSee('x-data="witnessPlacement', false)
        ->assertSee('\u003Cscript\u003Eunsafe-role', false)
        ->assertDontSee('<script>unsafe-role</script>', false)
        ->assertSee('data-flux-button', false)
        ->assertSee('<button class="witness-button" type="submit">Save details</button>', false)
        ->assertSee('<button class="witness-button" type="submit">Save placements</button>', false)
        ->assertSee('<input class="witness-file-input" type="file"', false);

    $document = new DOMDocument;
    @$document->loadHTML((string) $templateResponse->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " witness-button ") and normalize-space(.)="Replace"]')->length)->toBe(1);

    $templateIslands = $xpath->query('//script[@type="application/json" and @data-placement-data]');
    expect($templateIslands->length)->toBe(1)
        ->and($templateIslands->item(0)->textContent)->toContain('0.173456')
        ->toContain('0.234567')
        ->toContain('0.314159')
        ->toContain('0.092857')
        ->toContain('\u003Cscript\u003Eunsafe-role');

    $this->get(route('sender.envelopes.create'))
        ->assertOk()
        ->assertSee('<input class="witness-file-input" type="file"', false);

    $this->get(route('sender.templates.create'))
        ->assertOk()
        ->assertSee('<input class="witness-file-input" type="file"', false);

    $senderScript = (string) file_get_contents(public_path('build/assets/sender.js'));
    $senderStyles = (string) file_get_contents(public_path('build/assets/sender.css'));
    $dashboardView = (string) file_get_contents(resource_path('views/sender/dashboard.blade.php'));
    $setupView = (string) file_get_contents(resource_path('views/sender/setup.blade.php'));
    $deleteView = (string) file_get_contents(resource_path('views/sender/envelopes/delete.blade.php'));
    $envelopeView = (string) file_get_contents(resource_path('views/livewire/sender/envelope-editor.blade.php'));
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
        ->and($senderStyles)->toContain('input.witness-file-input::file-selector-button')
        ->and($senderStyles)->toContain('background:var(--w-rust)')
        ->and($senderStyles)->toContain('.witness-select')
        ->and($senderStyles)->toContain('.witness-status-draft')
        ->and($senderStyles)->toContain('.witness-status-sent')
        ->and($senderStyles)->toContain('.witness-status-completion_failed')
        ->and($senderStyles)->toContain('.witness-status-completed')
        ->and($senderStyles)->toContain('.witness-status-declined')
        ->and($senderStyles)->toContain('.witness-status-voided')
        ->and($senderStyles)->toContain('.witness-status-expired')
        ->and($dashboardView)->toContain('<select class="witness-select witness-status-select"', '<button class="witness-button" type="submit">Filter</button>')
        ->and($setupView)->toContain('<button class="witness-button" type="submit">Send me a test email</button>')
        ->and($deleteView)->toContain('<flux:button type="submit" variant="danger">Permanently delete</flux:button>')
        ->and($envelopeView)->toContain(
            '<button class="witness-button witness-button-small" type="submit">Resend link</button>',
            '<button class="witness-button" type="submit">Retry completion</button>',
            '<button class="witness-button" type="submit">Create a new delivery generation</button>',
            '<button class="witness-button" type="submit">Issue fresh final-document link</button>',
        )
        ->and($envelopeView)->toContain(
            '<flux:button type="submit" variant="danger">Void envelope</flux:button>',
            'class="witness-stack witness-recovery"',
            'variant="danger" size="sm" data-testid="delete-envelope"',
        )
        ->and((string) file_get_contents(resource_path('views/livewire/sender/template-editor.blade.php')))->toContain(
            '<flux:button :href="route(\'sender.templates.send-form\', $template)" variant="primary">Use template</flux:button>',
            '<flux:button type="button" x-on:click="add()">Add role</flux:button>',
            '<flux:button type="submit" variant="primary">Save roles</flux:button>',
            'variant="primary" class="witness-button-full">Map signers</flux:button>',
        )
        ->and((string) file_get_contents(resource_path('views/sender/templates/index.blade.php')))->toContain(
            '<a class="witness-button-secondary" href="{{ route(\'sender.templates.send-form\', $template) }}">Use template</a>',
        )
        ->and($canvasWidth)->toBeInt()
        ->and($canvasHeight)->toBeInt()
        ->and($render)->toBeInt();

    assert(is_int($canvasWidth) && is_int($canvasHeight) && is_int($render));
    expect($canvasWidth)->toBeLessThan($render)
        ->and($canvasHeight)->toBeLessThan($render);
});

it('inflects one-page envelope and template copy', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user);
    $pdf = PdfFixture::make();
    $path = 'documents/'.$team->getKey().'/single-page.pdf';
    Storage::put($path, $pdf, ['visibility' => 'private']);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        $team,
        $user,
        'Single page envelope',
        'single-page.pdf',
        $path,
        strlen($pdf),
        1,
        hash('sha256', $pdf),
    );
    $template = Template::factory()->forOwner($team, $user)->create([
        'original_name' => 'single-page-template.pdf',
        'page_count' => 1,
    ]);

    $this->actingAsVersioned($user)->withSession(['witness.team_id' => $team->getKey()])
        ->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertSeeText('single-page.pdf · 1 page')
        ->assertDontSee('1 pages');
    $this->get(route('sender.templates.edit', $template))
        ->assertOk()
        ->assertSeeText('single-page-template.pdf · 1 page')
        ->assertSee('across 1 page.')
        ->assertDontSee('1 pages');
});
