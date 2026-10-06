<?php

declare(strict_types=1);

use App\Models\Envelope;
use App\Models\Team;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

it('creates a personal team and stores inspected PDF bytes privately', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $pdf = PdfFixture::make(2);

    $response = $this->actingAsVersioned($user)->post(route('sender.envelopes.store'), [
        'title' => 'Agency agreement',
        'document' => UploadedFile::fake()->createWithContent('agreement.pdf', $pdf),
    ]);

    $envelope = Envelope::query()->with('document')->sole();
    $response->assertRedirect(route('sender.envelopes.edit', $envelope));
    expect(Team::query()->count())->toBe(1)
        ->and($envelope->sender_id)->toBe($user->getKey())
        ->and($envelope->document->page_count)->toBe(2)
        ->and($envelope->document->original_sha256)->toBe(hash('sha256', $pdf))
        ->and($envelope->document->storage_path)->not->toContain('agreement.pdf');
    Storage::disk('local')->assertExists($envelope->document->storage_path);
    expect(Storage::disk('local')->get($envelope->document->storage_path))->toBe($pdf);
});

it('rejects spoofed encrypted empty too-large and over-page-limit uploads without storing them', function (UploadedFile $file, string $message): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();

    $this->actingAsVersioned($user)->from(route('sender.envelopes.create'))->post(route('sender.envelopes.store'), [
        'title' => 'Rejected document',
        'document' => $file,
    ])->assertRedirect(route('sender.envelopes.create'))->assertSessionHasErrors(['document' => $message]);

    expect(Envelope::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'spoofed content' => [fn () => UploadedFile::fake()->createWithContent('spoof.pdf', 'not a pdf'), 'Choose a valid PDF document.'],
    'encrypted PDF' => [fn () => UploadedFile::fake()->createWithContent('encrypted.pdf', PdfFixture::make(1, true)), 'Encrypted or password-protected PDFs are not supported.'],
    'zero pages' => [fn () => UploadedFile::fake()->createWithContent('empty.pdf', PdfFixture::make(0)), 'The PDF must contain between 1 and 100 pages.'],
    'over 100 pages' => [fn () => UploadedFile::fake()->createWithContent('long.pdf', PdfFixture::make(101)), 'The PDF must contain between 1 and 100 pages.'],
    'over 20 MiB' => [fn () => UploadedFile::fake()->create('large.pdf', 20481, 'application/pdf'), 'The document field must not be greater than 20480 kilobytes.'],
]);

it('replaces a draft PDF atomically and removes the previous private object and placements', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $this->actingAsVersioned($user)->post(route('sender.envelopes.store'), [
        'title' => 'Replace me',
        'document' => UploadedFile::fake()->createWithContent('first.pdf', PdfFixture::make()),
    ]);
    $envelope = Envelope::query()->with('document')->firstOrFail();
    $oldPath = $envelope->document->storage_path;
    $signer = $envelope->signers()->create(['name' => 'Signer', 'email' => 'signer@example.test']);
    $signer->fields()->create(['page' => 1, 'type' => 'signature', 'required' => true, 'position_x' => .1, 'position_y' => .1, 'width' => .2, 'height' => .1]);
    $replacement = PdfFixture::make(3);

    $this->put(route('sender.envelopes.document', $envelope), [
        'document' => UploadedFile::fake()->createWithContent('replacement.pdf', $replacement),
    ])->assertRedirect();

    $document = $envelope->document()->firstOrFail();
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($document->storage_path);
    expect($document->page_count)->toBe(3)
        ->and($document->original_sha256)->toBe(hash('sha256', $replacement))
        ->and($envelope->fields()->count())->toBe(0);
});
