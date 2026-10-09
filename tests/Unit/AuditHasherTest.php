<?php

declare(strict_types=1);

use App\Domain\Audit\AuditHasher;

it('compares hashes for equality and refuses different hashes of any length', function (): void {
    $hasher = resolve(AuditHasher::class);

    expect($hasher->matches('same-bytes', 'same-bytes'))->toBeTrue()
        ->and($hasher->matches('known-hash', 'known-hast'))->toBeFalse()
        ->and($hasher->matches('short', 'a-much-longer-string'))->toBeFalse()
        ->and($hasher->matches('', ''))->toBeTrue();
});

it('compares through the constant-time hash_equals primitive rather than a short-circuiting operator', function (): void {
    $method = new ReflectionMethod(AuditHasher::class, 'matches');
    $lines = file($method->getFileName());
    $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    expect($body)->toContain('hash_equals(');
});
