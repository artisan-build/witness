<?php

declare(strict_types=1);

namespace App\Domain\Audit;

final class AuditHasher
{
    public function genesis(int $envelopeId, string $originalDocumentSha256): string
    {
        return hash('sha256', "witness:audit:v1:{$envelopeId}:{$originalDocumentSha256}");
    }

    public function eventHash(string $previousHash, string $canonicalEvent): string
    {
        return hash('sha256', $previousHash.$canonicalEvent);
    }

    public function matches(string $knownHash, string $calculatedHash): bool
    {
        return hash_equals($knownHash, $calculatedHash);
    }
}
