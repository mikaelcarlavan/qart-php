<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/** Contrainte de luminance : teinte libre, luminance imposée. */
final class Luma
{
    /**
     * Ajuste une couleur [r,g,b] 0..1 à la luminance cible en gardant la teinte.
     *
     * @param  array{0:float,1:float,2:float}  $c
     * @return array{0:float,1:float,2:float}
     */
    public static function apply(array $c, float $tl): array
    {
        $l = 0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2];
        if ($l >= $tl) {
            $ratio = $l > 1e-6 ? $tl / $l : 0.0;

            return [min(1, $c[0] * $ratio), min(1, $c[1] * $ratio), min(1, $c[2] * $ratio)];
        }
        $t = (1 - $l) > 1e-6 ? ($tl - $l) / (1 - $l) : 1.0;

        return [
            min(1, $c[0] + (1 - $c[0]) * $t),
            min(1, $c[1] + (1 - $c[1]) * $t),
            min(1, $c[2] + (1 - $c[2]) * $t),
        ];
    }
}
