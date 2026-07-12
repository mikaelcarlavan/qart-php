<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use chillerlan\QRCode\Common\EccLevel;

/**
 * Niveau de correction d'erreur Reed-Solomon.
 *
 * Compromis : un niveau élevé réduit la capacité (moins de variables libres
 * pour le solveur) mais augmente le budget d'erreur sacrifiable — surtout
 * pertinent pour les rendus plein module ; en halftone, L reste le défaut
 * recommandé.
 */
enum Ecc: string
{
    case L = 'L';   // ~7 % de correction
    case M = 'M';   // ~15 %
    case Q = 'Q';   // ~25 %
    case H = 'H';   // ~30 %

    /** Constante chillerlan correspondante. */
    public function level(): int
    {
        return match ($this) {
            self::L => EccLevel::L,
            self::M => EccLevel::M,
            self::Q => EccLevel::Q,
            self::H => EccLevel::H,
        };
    }
}
