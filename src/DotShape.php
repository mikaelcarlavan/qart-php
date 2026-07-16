<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/** Forme des points de données (le coeur DxD de chaque module). */
enum DotShape: string
{
    case Square = 'square';
    case Round = 'round';
    case Diamond = 'diamond';

    /**
     * Largeur de boîte du point pour un coeur $d x $d. Le losange est
     * élargi d'un facteur racine de 2 pour égaler l'aire du carré :
     * inscrit dans la même boîte il n'en couvrirait que la moitié, et le
     * flou optique d'un téléphone efface ce qui reste (relevés terrain
     * 2026-07-16 : losanges illisibles à l'écran là où les carrés passent).
     */
    public function span(float $d): float
    {
        return $this === self::Diamond ? $d * M_SQRT2 : $d;
    }
}
