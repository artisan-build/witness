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
    $source = file_get_contents($method->getFileName());
    $open = strpos($source, '{', (int) strpos($source, 'function matches'));
    $depth = 0;
    $close = $open;
    for ($i = $open, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        }
        if ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $close = $i;
                break;
            }
        }
    }
    $body = trim(substr($source, $open + 1, $close - $open - 1));
    $normalized = preg_replace('/\s+/', ' ', $body);

    expect($normalized)->toBe('return hash_equals($knownHash, $calculatedHash);')
        ->and($body)->not->toContain('===')
        ->and($body)->not->toContain('!==')
        ->and($body)->not->toContain('==')
        ->and($body)->not->toContain('!=')
        ->and($body)->not->toContain('&&')
        ->and($body)->not->toContain('||')
        ->and($body)->not->toContain('?:')
        ->and($body)->not->toContain(' ? ')
        ->and($body)->not->toContain('match(')
        ->and($body)->not->toContain('strlen')
        ->and($body)->not->toContain('if (');
});

it('hashes each event over its predecessor hash concatenated with the canonical bytes', function (): void {
    $hasher = resolve(AuditHasher::class);
    $previous = str_repeat('ab', 32);
    $canonical = '{"type":"sent","sequence":2}';

    expect($hasher->eventHash($previous, $canonical))
        ->toBe(hash('sha256', $previous.$canonical))
        ->and($hasher->eventHash('one', $canonical))
        ->not->toBe($hasher->eventHash('two', $canonical));
});
