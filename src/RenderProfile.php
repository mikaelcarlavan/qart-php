<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Exception\QArtException;

/**
 * Paramètres de rendu halftone. Deux profils par défaut :
 * - screen : luminances douces, adapté à l'affichage écran ;
 * - print  : points plus contrastés et échelle supérieure — l'impression
 *   (surtout CMJN) écrase les nuances, à recalibrer sur matrice de tests
 *   terrain (Phase 2 du plan).
 *
 * Le style (forme des points, finders arrondis, couleur de marque des
 * finders) est orthogonal au calibrage : utiliser les withers.
 */
final class RenderProfile
{
    private const FINDER_LUMA_MAX = 0.35;

    public function __construct(
        public readonly int $scale = 3,
        public readonly int $borderModules = 4,
        public readonly float $lDark = 0.35,
        public readonly float $lLight = 0.68,
        public readonly float $lDotDark = 0.12,
        public readonly float $lDotLight = 0.90,
        public readonly DotShape $dotShape = DotShape::Square,
        public readonly FinderShape $finderShape = FinderShape::Square,
        public readonly ?string $finderColor = null,
        public readonly RenderMode $mode = RenderMode::Halftone,
        public readonly Dithering $dithering = Dithering::Atkinson,
    ) {
        if ($finderColor !== null && self::luminanceOf($finderColor) > self::FINDER_LUMA_MAX) {
            throw new QArtException(sprintf(
                'couleur de finder trop claire (%s) : luminance max %.2f pour rester détectable',
                $finderColor, self::FINDER_LUMA_MAX
            ));
        }
    }

    public static function screen(): self
    {
        return new self;
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
            'print' => self::print(),
            default => throw new QArtException("profil de rendu inconnu: $name"),
        };
    }

    /** Pixel art plein module : voir RenderMode::Module. */
    public function withMode(RenderMode $mode): self
    {
        return $this->with(['mode' => $mode]);
    }

    /** Algorithme de dithering (texture halftone et cible pixel art). */
    public function withDithering(Dithering $dithering): self
    {
        return $this->with(['dithering' => $dithering]);
    }

    public function withDotShape(DotShape $shape): self
    {
        return $this->with(['dotShape' => $shape]);
    }

    public function withFinderShape(FinderShape $shape): self
    {
        return $this->with(['finderShape' => $shape]);
    }

    /** @param string|null $hex couleur sombre "#rrggbb" (luminance <= 0.35), null = noir */
    public function withFinderColor(?string $hex): self
    {
        return $this->with(['finderColor' => $hex]);
    }

    /** @return array{0:float,1:float,2:float} couleur des modules sombres de finder en [r,g,b] 0..1 */
    public function finderRgb(): array
    {
        return $this->finderColor === null ? [0.0, 0.0, 0.0] : self::hexToRgb($this->finderColor);
    }

    /** @param array<string, mixed> $overrides */
    private function with(array $overrides): self
    {
        $args = [
            'scale' => $this->scale,
            'borderModules' => $this->borderModules,
            'lDark' => $this->lDark,
            'lLight' => $this->lLight,
            'lDotDark' => $this->lDotDark,
            'lDotLight' => $this->lDotLight,
            'dotShape' => $this->dotShape,
            'finderShape' => $this->finderShape,
            'finderColor' => $this->finderColor,
            'mode' => $this->mode,
            'dithering' => $this->dithering,
        ];

        return new self(...array_merge($args, $overrides));
    }

    /** @return array{0:float,1:float,2:float} */
    private static function hexToRgb(string $hex): array
    {
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            throw new QArtException("couleur invalide: $hex (attendu #rrggbb)");
        }

        return [
            hexdec(substr($hex, 1, 2)) / 255.0,
            hexdec(substr($hex, 3, 2)) / 255.0,
            hexdec(substr($hex, 5, 2)) / 255.0,
        ];
    }

    private static function luminanceOf(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }
}
