<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/**
 * Traitement des images non carrées :
 * - Cover   : recadrage carré (fenêtre choisie ou centrée) — l'image
 *   remplit le QR, une partie est perdue ;
 * - Contain : toute l'image est gardée, centrée sur un carré blanc qui
 *   se fond dans la quiet zone — adapté aux logos larges ou hauts.
 */
enum Fit: string
{
    case Cover = 'cover';
    case Contain = 'contain';
}
