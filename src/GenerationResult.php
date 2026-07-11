<?php

declare(strict_types=1);

namespace SqrArt\QArt;

final class GenerationResult
{
    /** @param string[] $warnings */
    public function __construct(
        public readonly string $url,
        public readonly string $suffix,
        public readonly string $serial,
        public readonly int $mask,
        public readonly string $pngPath,
        public readonly int $attempts,
        public readonly array $warnings,
        public readonly ?string $svgPath = null,
        /** Modules de zones protégées non fidèles à l'image (null si aucune zone). */
        public readonly ?int $protectedMismatches = null,
    ) {}
}
