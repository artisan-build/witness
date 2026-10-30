<?php

declare(strict_types=1);

it('renders only the requested draft section with the envelope data', function (string $step, string $section): void {
    [$envelope, $signer] = createSigningEnvelope(send: false);

    $response = $this->actingAsVersioned($envelope->sender)
        ->withSession(['witness.team_id' => $envelope->team_id])
        ->get(route('sender.envelopes.edit', ['envelope' => $envelope, 'step' => $step]))
        ->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML((string) $response->getContent());
    $xpath = new DOMXPath($document);
    $sections = $xpath->query('//*[@data-testid="envelope-editor"]/section');

    expect($sections->length)->toBe(1)
        ->and($sections->item(0)->getAttribute('data-testid'))->toBe($section);

    $root = $sections->item(0);
    if ($section === 'envelope-details') {
        expect($xpath->query('.//input[@name="title"]', $root)->item(0)->getAttribute('value'))->toBe($envelope->title);
    } elseif ($section === 'signer-editor') {
        expect($xpath->query('.//form[@data-repeater="signers"]', $root)->item(0)->getAttribute('x-data'))
            ->toContain($signer->name, $signer->email);
    } elseif ($section === 'field-placement') {
        $data = json_decode($xpath->query('.//script[@data-placement-data]', $root)->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        expect($xpath->query('.//form[@data-placement-root]//canvas[@data-testid="pdf-page-canvas"]', $root)->length)->toBe(1)
            ->and($data['assignees'])->toBe([['id' => $signer->getKey(), 'name' => $signer->name]])
            ->and($data['fields'][0]['assignee_id'])->toBe($signer->getKey())
            ->and($data['fields'][0]['position_x'])->toBe((float) $signer->fields()->firstOrFail()->position_x);
    } else {
        expect($xpath->query('.//*[@data-testid="review-signers"]', $root)->item(0)->textContent)
            ->toContain($signer->name, $signer->email)
            ->and($xpath->query('.//dd', $root)->item(0)->textContent)->toBe($envelope->document->original_name)
            ->and($xpath->query('.//form/button[@data-testid="send-envelope"]', $root)->length)->toBe(1);
    }
})->with([
    'document' => ['details', 'envelope-details'],
    'signers' => ['signers', 'signer-editor'],
    'fields' => ['fields', 'field-placement'],
    'review' => ['review', 'envelope-review'],
    'invalid step falls back to document' => ['unknown-step', 'envelope-details'],
]);
