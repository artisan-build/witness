<?php

declare(strict_types=1);

namespace App\Services;

final readonly class CompletedPdf
{
    public function __construct(
        public string $bytes,
        public string $sha256,
        public string $contentSha256,
        public int $sourcePageCount,
        public int $certificatePageCount,
        public string $certificateChainHead,
    ) {}
}
