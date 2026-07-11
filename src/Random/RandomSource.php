<?php

declare(strict_types=1);

namespace SqrArt\QArt\Random;

/** Source d'aléa injectable : seedable en test, cryptographique en production. */
interface RandomSource
{
    /** Entier uniforme dans [$min, $max] inclus. */
    public function int(int $min, int $max): int;
}
