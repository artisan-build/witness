<?php

declare(strict_types=1);

use App\Support\CanonicalJson;

it('canonicalizes semantically identical associative payloads deterministically', function (): void {
    $canonical = new CanonicalJson;

    $first = $canonical->encode([
        'nested' => ['beta' => 2, 'alpha' => 1],
        'name' => 'Test-created value',
    ]);
    $second = $canonical->encode([
        'name' => 'Test-created value',
        'nested' => ['alpha' => 1, 'beta' => 2],
    ]);

    expect($first)->toBe($second);
});

it('encodes to the exact canonical bytes with sorted keys, list order preserved, and unescaped output', function (): void {
    $canonical = new CanonicalJson;

    $encoded = $canonical->encode([
        'nested' => ['beta' => 2, 'alpha' => ['z' => 'škoda/2.0', 'a' => 1.5, 'ratio' => 2.0]],
        'name' => 'Test-created value',
        'items' => ['second', 'first'],
    ]);

    expect($encoded)->toBe(
        '{"items":["second","first"],"name":"Test-created value",'
        .'"nested":{"alpha":{"a":1.5,"ratio":2.0,"z":"škoda/2.0"},"beta":2}}',
    );
});

it('throws on payloads that cannot be encoded', function (): void {
    $canonical = new CanonicalJson;

    $canonical->encode(['bad' => "\xB1\x31"]);
})->throws(JsonException::class);
