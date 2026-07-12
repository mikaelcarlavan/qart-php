<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/**
 * Mode de rendu des modules de données :
 * - Halftone : texture 7x7 sous-pixels + point central 3x3 (historique) ;
 * - Module   : pixel art plein module — chaque module est un carré plein,
 *   le QR lui-même est l'image dithérée à la résolution des modules.
 *   Recommandé avec Ecc::H et un budget d'erreur élevé : la capacité perdue
 *   ne coûte rien (moins de modules à contrôler) et chaque codeword
 *   sacrifié force 8 pixels de plus à l'image.
 */
enum RenderMode: string
{
    case Halftone = 'halftone';
    case Module = 'module';
}
