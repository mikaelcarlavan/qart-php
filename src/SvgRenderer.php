<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Exception\QArtException;

/**
 * Rendu halftone vectoriel : mêmes règles visuelles que le renderer GD
 * (texture dithérée teintée, points DxD contraints en luminance), en SVG
 * imprimable à toute taille.
 *
 * Poids maîtrisé : les sous-pixels de texture sont fusionnés par plages
 * horizontales de couleur identique (couleurs quantifiées à 32 niveaux par
 * canal, imperceptible) ; les points sont un élément par module.
 *
 * Unité interne : le sous-pixel (module = 7 unités). Le SVG étant
 * vectoriel, le paramètre scale du profil ne s'applique pas ici.
 */
final class SvgRenderer
{
    /** Quantification des canaux : multiples de 8 => plages plus longues. */
    private const QUANT = 8;

    /** @param int[][] $matrix modules finaux (après budget d'erreur) */
    public static function colorHalftone(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        RenderProfile $profile,
    ): string {
        $n = $spec->n;
        $s = ImagePipeline::S;
        $d = ImagePipeline::D;
        $off = intdiv($s - $d, 2);
        $border = $profile->borderModules * $s;
        $size = $n * $s + 2 * $border;

        $svg = [];
        $svg[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" shape-rendering="crispEdges">',
            $size, $size
        );
        $svg[] = sprintf('<rect width="%d" height="%d" fill="#ffffff"/>', $size, $size);

        // 1. Texture dithérée : grille de couleurs puis fusion par plages
        $svg[] = '<g>';
        $hi = $img->hi;
        for ($y = 0; $y < $hi; $y++) {
            $runColor = null;
            $runStart = 0;
            $flush = function (int $end) use (&$runColor, &$runStart, &$svg, $y, $border): void {
                if ($runColor !== null) {
                    $svg[] = sprintf(
                        '<rect x="%d" y="%d" width="%d" height="1" fill="%s"/>',
                        $border + $runStart, $border + $y, $end - $runStart, $runColor
                    );
                }
                $runColor = null;
            };
            $r = intdiv($y, $s);
            for ($x = 0; $x < $hi; $x++) {
                $c = intdiv($x, $s);
                if ($spec->fmap[$r][$c]) {
                    $flush($x);

                    continue;
                }
                $dark = $img->dith[$y][$x] === 1;
                $color = self::hex(Luma::apply($img->rgb[$y][$x], $dark ? $profile->lDark : $profile->lLight));
                if ($color !== $runColor) {
                    $flush($x);
                    $runColor = $color;
                    $runStart = $x;
                }
            }
            $flush($hi);
        }
        $svg[] = '</g>';

        // 2. Points de données par-dessus la texture
        $svg[] = '<g>';
        $half = $d / 2;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($spec->fmap[$r][$c]) {
                    continue;
                }
                $bit = $matrix[$r][$c];
                $cx = $border + $c * $s + $off + $half;
                $cy = $border + $r * $s + $off + $half;
                $rgb = $img->rgb[$r * $s + $off + 1][$c * $s + $off + 1];
                $fill = self::hex(Luma::apply($rgb, $bit ? $profile->lDotDark : $profile->lDotLight));
                $svg[] = match ($profile->dotShape) {
                    DotShape::Square => sprintf(
                        '<rect x="%s" y="%s" width="%d" height="%d" fill="%s"/>',
                        self::num($cx - $half), self::num($cy - $half), $d, $d, $fill
                    ),
                    DotShape::Round => sprintf(
                        '<circle cx="%s" cy="%s" r="%s" fill="%s"/>',
                        self::num($cx), self::num($cy), self::num($half), $fill
                    ),
                    DotShape::Diamond => sprintf(
                        '<path d="M%s %sL%s %sL%s %sL%s %sZ" fill="%s"/>',
                        self::num($cx), self::num($cy - $half),
                        self::num($cx + $half), self::num($cy),
                        self::num($cx), self::num($cy + $half),
                        self::num($cx - $half), self::num($cy), $fill
                    ),
                };
            }
        }
        $svg[] = '</g>';

        // 3. Modules de fonction (hors finders si style dédié)
        $rounded = $profile->finderShape === FinderShape::Rounded;
        // couleur exacte (pas de quantification : c'est un choix utilisateur)
        $finderFill = strtolower($profile->finderColor ?? '#000000');
        $svg[] = '<g>';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (! $spec->fmap[$r][$c] || ! $matrix[$r][$c]) {
                    continue;
                }
                $inFinder = self::inFinder($r, $c, $n);
                if ($rounded && $inFinder) {
                    continue;   // dessinés en formes dédiées ci-dessous
                }
                $svg[] = sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>',
                    $border + $c * $s, $border + $r * $s, $s, $s,
                    $inFinder ? $finderFill : '#000000'
                );
            }
        }
        $svg[] = '</g>';

        // 4. Finders arrondis : anneau 7x7 + coeur 3x3
        if ($rounded) {
            foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$fr, $fc]) {
                $x = $border + $fc * $s;
                $y = $border + $fr * $s;
                $svg[] = sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d" rx="%s" fill="%s"/>'
                    .'<rect x="%d" y="%d" width="%d" height="%d" rx="%s" fill="#ffffff"/>'
                    .'<rect x="%d" y="%d" width="%d" height="%d" rx="%s" fill="%s"/>',
                    $x, $y, 7 * $s, 7 * $s, self::num(7 * $s / 3), $finderFill,
                    $x + $s, $y + $s, 5 * $s, 5 * $s, self::num(5 * $s / 3.75),
                    $x + 2 * $s, $y + 2 * $s, 3 * $s, 3 * $s, self::num($s), $finderFill
                );
            }
        }

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    public static function toFile(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        string $out,
        RenderProfile $profile,
    ): void {
        if (@file_put_contents($out, self::colorHalftone($img, $spec, $matrix, $profile)) === false) {
            throw new QArtException("écriture du SVG impossible: $out");
        }
    }

    /** Les trois zones finder 7x7. */
    private static function inFinder(int $r, int $c, int $n): bool
    {
        return ($r < 7 && $c < 7)
            || ($r < 7 && $c >= $n - 7)
            || ($r >= $n - 7 && $c < 7);
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private static function hex(array $rgb): string
    {
        $q = fn (float $v): int => min(255, (int) (round($v * 255 / self::QUANT) * self::QUANT));

        return sprintf('#%02x%02x%02x', $q($rgb[0]), $q($rgb[1]), $q($rgb[2]));
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
