<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use GdImage;
use SqrArt\QArt\Exception\QArtException;

/**
 * Rendu halftone couleur raster (GD) : texture dithérée teintée par l'image,
 * points DxD forcés à la valeur des modules, couleur contrainte en
 * luminance. Géométrie alignée sur SvgRenderer (mêmes formes de points,
 * mêmes finders) pour que la préview PNG corresponde au SVG.
 */
final class Renderer
{
    /** @param int[][] $matrix modules finaux (après budget d'erreur) */
    public static function colorHalftone(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        string $out,
        RenderProfile $profile,
    ): void {
        $n = $spec->n;
        $s = ImagePipeline::S;
        $d = ImagePipeline::D;
        $scale = $profile->scale;
        $off = intdiv($s - $d, 2);
        $hi = $img->hi;
        $border = $profile->borderModules * $s;
        $size = ($hi + 2 * $border) * $scale;
        $im = imagecreatetruecolor($size, $size);
        imagefilledrectangle($im, 0, 0, $size, $size, 0xFFFFFF);
        imageantialias($im, true);

        $toInt = fn (array $c): int => ((int) round($c[0] * 255) << 16)
            | ((int) round($c[1] * 255) << 8)
            | (int) round($c[2] * 255);
        $put = function (int $y, int $x, array $c) use ($im, $border, $scale, $toInt): void {
            $px = ($x + $border) * $scale;
            $py = ($y + $border) * $scale;
            imagefilledrectangle($im, $px, $py, $px + $scale - 1, $py + $scale - 1, $toInt($c));
        };

        $rounded = $profile->finderShape === FinderShape::Rounded;
        $finderInt = $toInt($profile->finderRgb());

        // 1. Texture + modules de fonction
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $y0 = $r * $s;
                $x0 = $c * $s;
                if ($spec->fmap[$r][$c]) {
                    $inFinder = self::inFinder($r, $c, $n);
                    if ($rounded && $inFinder) {
                        continue;   // zone repeinte en formes dédiées plus bas
                    }
                    $dark = (bool) $matrix[$r][$c];
                    $fill = $dark ? ($inFinder ? $finderInt : 0x000000) : 0xFFFFFF;
                    for ($y = 0; $y < $s; $y++) {
                        for ($x = 0; $x < $s; $x++) {
                            $px = ($x0 + $x + $border) * $scale;
                            $py = ($y0 + $y + $border) * $scale;
                            imagefilledrectangle($im, $px, $py, $px + $scale - 1, $py + $scale - 1, $fill);
                        }
                    }

                    continue;
                }
                for ($y = 0; $y < $s; $y++) {
                    for ($x = 0; $x < $s; $x++) {
                        $dark = $img->dith[$y0 + $y][$x0 + $x] === 1;
                        $base = $img->rgb[$y0 + $y][$x0 + $x];
                        $put($y0 + $y, $x0 + $x, Luma::apply($base, $dark ? $profile->lDark : $profile->lLight));
                    }
                }
            }
        }

        // 2. Points de données par-dessus la texture
        $half = $d * $scale / 2;
        $dh = (int) round($profile->dotShape->span($d) * $scale / 2);   // demi-diagonale du losange
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($spec->fmap[$r][$c]) {
                    continue;
                }
                $bit = $matrix[$r][$c];
                $y0 = $r * $s;
                $x0 = $c * $s;
                $rgb = $img->rgb[$y0 + $off + 1][$x0 + $off + 1];
                $fill = $toInt(Luma::apply($rgb, $bit ? $profile->lDotDark : $profile->lDotLight));
                $px = ($x0 + $off + $border) * $scale;
                $py = ($y0 + $off + $border) * $scale;
                $cx = (int) round($px + $half);
                $cy = (int) round($py + $half);
                $side = $d * $scale;
                match ($profile->dotShape) {
                    DotShape::Square => imagefilledrectangle($im, $px, $py, $px + $side - 1, $py + $side - 1, $fill),
                    DotShape::Round => imagefilledellipse($im, $cx, $cy, $side, $side, $fill),
                    DotShape::Diamond => imagefilledpolygon($im, [
                        $cx, $cy - $dh,
                        $cx + $dh, $cy,
                        $cx, $cy + $dh,
                        $cx - $dh, $cy,
                    ], $fill),
                };
            }
        }

        // 3. Finders arrondis : fond blanc 8x8 (séparateur compris) puis
        //    anneau 7x7 et coeur 3x3 en rounded rects
        if ($rounded) {
            foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$fr, $fc]) {
                $bx = ($fc * $s + $border) * $scale;
                $by = ($fr * $s + $border) * $scale;
                $ms = $s * $scale;
                // fond : zone 8x8 côté intérieur (le séparateur)
                $wx0 = $fc === 0 ? $bx : $bx - $ms;
                $wy0 = $fr === 0 ? $by : $by - $ms;
                imagefilledrectangle($im, $wx0, $wy0, $wx0 + 8 * $ms - 1, $wy0 + 8 * $ms - 1, 0xFFFFFF);
                self::roundedRect($im, $bx, $by, 7 * $ms, (int) round(7 * $ms / 3), $finderInt);
                self::roundedRect($im, $bx + $ms, $by + $ms, 5 * $ms, (int) round(5 * $ms / 3.75), 0xFFFFFF);
                self::roundedRect($im, $bx + 2 * $ms, $by + 2 * $ms, 3 * $ms, $ms, $finderInt);
            }
        }

        if (! imagepng($im, $out)) {
            throw new QArtException("écriture du PNG impossible: $out");
        }
    }

    /**
     * Pixel art plein module : chaque module est un carré plein teinté par la
     * couleur moyenne de l'image (luminance contrainte par lDotDark/lDotLight).
     * Le QR entier est l'image — pas de texture, pas de points. Mêmes
     * dimensions de sortie que le halftone (module = S sous-pixels x scale).
     *
     * @param  int[][]  $matrix  modules finaux (après budget d'erreur)
     */
    public static function pixelArt(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        string $out,
        RenderProfile $profile,
    ): void {
        $n = $spec->n;
        $s = ImagePipeline::S;
        $scale = $profile->scale;
        $border = $profile->borderModules * $s;
        $size = ($n * $s + 2 * $border) * $scale;
        $im = imagecreatetruecolor($size, $size);
        imagefilledrectangle($im, 0, 0, $size, $size, 0xFFFFFF);

        $toInt = fn (array $c): int => ((int) round($c[0] * 255) << 16)
            | ((int) round($c[1] * 255) << 8)
            | (int) round($c[2] * 255);
        $rounded = $profile->finderShape === FinderShape::Rounded;
        $finderInt = $toInt($profile->finderRgb());
        $ms = $s * $scale;

        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $dark = (bool) $matrix[$r][$c];
                if ($spec->fmap[$r][$c]) {
                    if ($rounded && self::inFinder($r, $c, $n)) {
                        continue;   // repeints en formes dédiées plus bas
                    }
                    if (! $dark) {
                        continue;   // fond déjà blanc
                    }
                    $fill = self::inFinder($r, $c, $n) ? $finderInt : 0x000000;
                } else {
                    $fill = $toInt(Luma::apply(
                        $img->moduleRgb[$r][$c],
                        $dark ? $profile->lDotDark : $profile->lDotLight
                    ));
                }
                $px = ($c * $s + $border) * $scale;
                $py = ($r * $s + $border) * $scale;
                imagefilledrectangle($im, $px, $py, $px + $ms - 1, $py + $ms - 1, $fill);
            }
        }

        if ($rounded) {
            foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$fr, $fc]) {
                $bx = ($fc * $s + $border) * $scale;
                $by = ($fr * $s + $border) * $scale;
                self::roundedRect($im, $bx, $by, 7 * $ms, (int) round(7 * $ms / 3), $finderInt);
                self::roundedRect($im, $bx + $ms, $by + $ms, 5 * $ms, (int) round(5 * $ms / 3.75), 0xFFFFFF);
                self::roundedRect($im, $bx + 2 * $ms, $by + 2 * $ms, 3 * $ms, $ms, $finderInt);
            }
        }

        if (! imagepng($im, $out)) {
            throw new QArtException("écriture du PNG impossible: $out");
        }
    }

    private static function inFinder(int $r, int $c, int $n): bool
    {
        return ($r < 7 && $c < 7)
            || ($r < 7 && $c >= $n - 7)
            || ($r >= $n - 7 && $c < 7);
    }

    /** Carré arrondi plein : deux rects + quatre disques de coin. */
    private static function roundedRect(GdImage $im, int $x, int $y, int $side, int $radius, int $color): void
    {
        $x1 = $x + $side - 1;
        $y1 = $y + $side - 1;
        imagefilledrectangle($im, $x + $radius, $y, $x1 - $radius, $y1, $color);
        imagefilledrectangle($im, $x, $y + $radius, $x1, $y1 - $radius, $color);
        foreach ([[$x + $radius, $y + $radius], [$x1 - $radius, $y + $radius],
            [$x + $radius, $y1 - $radius], [$x1 - $radius, $y1 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $radius, 2 * $radius, $color);
        }
    }
}
