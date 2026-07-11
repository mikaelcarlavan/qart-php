<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Exception\QArtException;

/**
 * Rendu halftone couleur : texture dithérée teintée par l'image, points
 * centraux DxD forcés à la valeur des modules, couleur contrainte en
 * luminance (teinte libre, luminance imposée).
 */
final class Renderer
{
    /** Ajuste une couleur [r,g,b] à la luminance cible en gardant la teinte. */
    private static function setLuma(array $c, float $tl): array
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

    /** @param int[][] $matrix modules finaux (après budget d'erreur) */
    public static function colorHalftone(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        string $out,
        RenderProfile $profile,
    ): void {
        $n = QArtSpec::N;
        $s = ImagePipeline::S;
        $d = ImagePipeline::D;
        $scale = $profile->scale;
        $off = intdiv($s - $d, 2);
        $hi = ImagePipeline::HI;
        $border = $profile->borderModules * $s;
        $size = ($hi + 2 * $border) * $scale;
        $im = imagecreatetruecolor($size, $size);
        imagefilledrectangle($im, 0, 0, $size, $size, 0xFFFFFF);

        $put = function (int $y, int $x, array $c) use ($im, $border, $scale): void {
            $col = ((int) round($c[0] * 255) << 16) | ((int) round($c[1] * 255) << 8) | (int) round($c[2] * 255);
            $px = ($x + $border) * $scale;
            $py = ($y + $border) * $scale;
            imagefilledrectangle($im, $px, $py, $px + $scale - 1, $py + $scale - 1, $col);
        };

        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $y0 = $r * $s;
                $x0 = $c * $s;
                $bit = $matrix[$r][$c];
                if ($spec->fmap[$r][$c]) {
                    for ($y = 0; $y < $s; $y++) {
                        for ($x = 0; $x < $s; $x++) {
                            $put($y0 + $y, $x0 + $x, $bit ? [0, 0, 0] : [1, 1, 1]);
                        }
                    }
                    continue;
                }
                for ($y = 0; $y < $s; $y++) {
                    for ($x = 0; $x < $s; $x++) {
                        $inDot = $y >= $off && $y < $off + $d && $x >= $off && $x < $off + $d;
                        $base = $img->rgb[$y0 + $y][$x0 + $x];
                        if ($inDot) {
                            $put($y0 + $y, $x0 + $x, self::setLuma($base, $bit ? $profile->lDotDark : $profile->lDotLight));
                        } else {
                            $dark = $img->dith[$y0 + $y][$x0 + $x] === 1;
                            $put($y0 + $y, $x0 + $x, self::setLuma($base, $dark ? $profile->lDark : $profile->lLight));
                        }
                    }
                }
            }
        }
        if (!imagepng($im, $out)) {
            throw new QArtException("écriture du PNG impossible: $out");
        }
    }
}
