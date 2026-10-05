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
