<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/**
 * Algorithme de dithering, appliqué à la texture halftone (sous-pixels) et
 * à la cible pixel art (modules) :
 * - Atkinson : diffusion partielle (6/8), rendu doux, hautes lumières
 *   préservées — l'historique du package ;
 * - FloydSteinberg : diffusion complète, plus de détail, plus granuleux ;
 * - Ordered : matrice de Bayer 8x8, trame régulière — look rétro/imprimé ;
 * - None : seuillage brut à 0.5, aplats posterisés.
 */
enum Dithering: string
{
    case Atkinson = 'atkinson';
    case FloydSteinberg = 'floyd-steinberg';
    case Ordered = 'ordered';
    case None = 'none';

    /**
     * Noyau de diffusion d'erreur, null pour les modes sans diffusion.
     *
     * @return array{0: array<array{0:int,1:int,2:int}>, 1: int}|null [entrées [dy, dx, poids], diviseur]
     */
    public function kernel(): ?array
    {
        return match ($this) {
            self::Atkinson => [
                [[0, 1, 1], [0, 2, 1], [1, -1, 1], [1, 0, 1], [1, 1, 1], [2, 0, 1]], 8,
            ],
            self::FloydSteinberg => [
                [[0, 1, 7], [1, -1, 3], [1, 0, 5], [1, 1, 1]], 16,
            ],
            self::Ordered, self::None => null,
        };
    }

    /** Matrice de Bayer 8x8 (seuils 0..63) pour le mode Ordered. */
    public const BAYER8 = [
        [0, 32, 8, 40, 2, 34, 10, 42],
        [48, 16, 56, 24, 50, 18, 58, 26],
        [12, 44, 4, 36, 14, 46, 6, 38],
        [60, 28, 52, 20, 62, 30, 54, 22],
        [3, 35, 11, 43, 1, 33, 9, 41],
        [51, 19, 59, 27, 49, 17, 57, 25],
        [15, 47, 7, 39, 13, 45, 5, 37],
        [63, 31, 55, 23, 61, 29, 53, 21],
    ];
}
