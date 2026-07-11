<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/**
 * Paramètres de rendu halftone. Deux profils par défaut :
 * - screen : luminances douces, adapté à l'affichage écran ;
 * - print  : points plus contrastés et échelle supérieure — l'impression
 *   (surtout CMJN) écrase les nuances, à recalibrer sur matrice de tests
 *   terrain (Phase 2 du plan).
 */
final class RenderProfile
{
    public function __construct(
        public readonly int $scale = 3,
        public readonly int $borderModules = 4,
        public readonly float $lDark = 0.35,
        public readonly float $lLight = 0.68,
        public readonly float $lDotDark = 0.12,
        public readonly float $lDotLight = 0.90,
    ) {
    }

    public static function screen(): self
    {
        return new self();
    }

    public static function print(): self
    {
        return new self(
            scale: 4,
            lDark: 0.30,
            lLight: 0.72,
            lDotDark: 0.05,
            lDotLight: 0.95,
        );
    }

    public static function fromName(string $name): self
    {
        return match ($name) {
            'screen' => self::screen(),
            'print'  => self::print(),
            default  => throw new Exception\QArtException("profil de rendu inconnu: $name"),
        };
    }
}
