<?php

declare(strict_types=1);

namespace SqrArt\QArt\Random;

/**
 * PRNG déterministe (xorshift 62 bits) pour tests reproductibles et golden
 * tests. Ne pas utiliser en production : l'entropie de la série protège
 * contre l'énumération des suffixes.
 */
final class SeededRandom implements RandomSource
{
    private const MASK = 0x3FFFFFFFFFFFFFFF; // 62 bits : évite tout débordement signé

    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed !== 0 ? $seed : 0x1E3779B97F4A7C15) & self::MASK;
    }

    public function int(int $min, int $max): int
    {
        $x = ($this->state ^ ($this->state >> 12)) & self::MASK;
        $x = ($x ^ ($x << 25)) & self::MASK;
        $x = ($x ^ ($x >> 27)) & self::MASK;
        $this->state = $x;

        return $min + ($x % ($max - $min + 1));
    }
}
