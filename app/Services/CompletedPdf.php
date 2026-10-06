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
        /** @var list<array{page: int, width: float, height: float, rotation: int}> */
        public array $pages,
        /** @var list<array{page: int, type: string, kind: string, x: float, y: float, width: float, height: float}> */
        public array $placements,
    ) {}
}
