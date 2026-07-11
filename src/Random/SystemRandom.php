<?php

declare(strict_types=1);

namespace SqrArt\QArt\Random;

final class SystemRandom implements RandomSource
{
    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
