<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use GdImage;
use SqrArt\QArt\Exception\ImageException;

/**
 * Préparation de l'image : recadrage carré centré, autocontraste,
 * dithering Atkinson, cible et confiance par module.
 *
 * La grille dépend de la version QR visée : n modules de côté, 7 sous-pixels
 * par module. Cas limites gérés : formats PNG/JPEG/GIF/WebP/BMP, palettes et
 * alpha (aplatis sur fond blanc), image trop petite (upscale +
 * avertissement), contraste dégénéré (avertissement, ou refus si
 * inexploitable).
 */
final class ImagePipeline
{
    public const S = 7;    // sous-pixels par module

    public const D = 3;    // point central forcé

    private const CONTRAST_REJECT = 0.02;

    private const CONTRAST_WARN = 0.15;

    /** Modules de côté (dépend de la version QR). */
    public readonly int $n;

    /** Côté de la grille de sous-pixels (n * S). */
    public readonly int $hi;

    /** @var float[][] luminance 0..1 après autocontraste */
    public array $gray;

    /** @var float[][][] couleurs source [r,g,b] 0..1 */
    public array $rgb;

    /** @var int[][] dithering Atkinson, 1 = noir */
    public array $dith;

    /** @var int[][] cible par module, 1 = module noir souhaité */
    public array $target;

    /** @var float[][] confiance 0..1 par module */
    public array $conf;

    /** @var float[][][] couleur moyenne [r,g,b] 0..1 par module */
    public array $moduleRgb;

    /** @var string[] avertissements non bloquants (upscale, faible contraste…) */
    public array $warnings = [];

    /** @param array{x:float,y:float,size:float}|null $crop carré source en fractions (défaut : centré) */
    public static function fromFile(string $path, int $modules = 57, ?array $crop = null, bool $moduleDither = false, Dithering $dithering = Dithering::Atkinson): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new ImageException("image illisible: $path");
        }

        return self::fromString($data, $modules, $crop, $moduleDither, $dithering);
    }

    /** @param array{x:float,y:float,size:float}|null $crop */
    public static function fromString(string $data, int $modules = 57, ?array $crop = null, bool $moduleDither = false, Dithering $dithering = Dithering::Atkinson): self
    {
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            throw new ImageException('format d\'image non reconnu ou fichier corrompu');
        }

        return new self($src, $modules, $crop, $moduleDither, $dithering);
    }

    /**
     * @param  array{x:float,y:float,size:float}|null  $crop  carré source :
     *                                                        x,y en fractions de la largeur/hauteur, size en fraction du
     *                                                        petit côté. Null = carré centré (comportement historique).
     * @param  bool  $moduleDither  cible dithérée à la résolution des modules
     *                              (pixel art plein module) au lieu du seuil
     *                              sur le coeur 3x3 (halftone)
     * @param  Dithering  $dithering  algorithme appliqué à la texture et à la
     *                                cible pixel art
     */
    public function __construct(GdImage $src, int $modules = 57, ?array $crop = null, bool $moduleDither = false, Dithering $dithering = Dithering::Atkinson)
    {
        $this->n = $modules;
        $this->hi = $modules * self::S;
        $hi = $this->hi;

        $w = imagesx($src);
        $h = imagesy($src);
        if (min($w, $h) < 1) {
            throw new ImageException('image vide');
        }

        // Fenêtre de recadrage (carrée), centrée par défaut
        if ($crop !== null) {
            $side = max(8, (int) round($crop['size'] * min($w, $h)));
            $side = min($side, $w, $h);
            $sx = max(0, min($w - $side, (int) round($crop['x'] * $w)));
            $sy = max(0, min($h - $side, (int) round($crop['y'] * $h)));
        } else {
            $side = min($w, $h);
            $sx = intdiv($w - $side, 2);
            $sy = intdiv($h - $side, 2);
        }

        if ($side < $hi) {
            $this->warnings[] = sprintf(
                'zone recadrée %dpx plus petite que %d px de côté : agrandie, le rendu peut être flou',
                $side, $hi
            );
        }

        // Aplatissement palette/alpha sur fond blanc
        $flat = imagecreatetruecolor($w, $h);
        imagefilledrectangle($flat, 0, 0, $w - 1, $h - 1, 0xFFFFFF);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);

        // Recadrage + redimensionnement à la grille de sous-pixels
        $sq = imagecreatetruecolor($hi, $hi);
        imagecopyresampled($sq, $flat, 0, 0, $sx, $sy, $hi, $hi, $side, $side);

        $gray = [];
        $rgb = [];
        for ($y = 0; $y < $hi; $y++) {
            for ($x = 0; $x < $hi; $x++) {
                $px = imagecolorat($sq, $x, $y);
                $r = (($px >> 16) & 0xFF) / 255.0;
                $g = (($px >> 8) & 0xFF) / 255.0;
                $b = ($px & 0xFF) / 255.0;
                $rgb[$y][$x] = [$r, $g, $b];
                $gray[$y][$x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }

        // Autocontraste (percentiles 1/99) + contraste x1.15 autour de la moyenne
        $sorted = [];
        foreach ($gray as $row) {
            foreach ($row as $v) {
                $sorted[] = $v;
            }
        }
        sort($sorted);
        $lo = $sorted[(int) (count($sorted) * 0.01)];
        $hi2 = $sorted[(int) (count($sorted) * 0.99)];
        $mean = array_sum($sorted) / count($sorted);
        $span = $hi2 - $lo;
        if ($span < self::CONTRAST_REJECT) {
            throw new ImageException(
                'image sans contraste exploitable (aplat quasi uniforme) : le dithering serait dégénéré'
            );
        }
        if ($span < self::CONTRAST_WARN) {
            $this->warnings[] = 'contraste très faible : la fidélité visuelle sera limitée';
        }
        for ($y = 0; $y < $hi; $y++) {
            for ($x = 0; $x < $hi; $x++) {
                $v = (max(min($gray[$y][$x], $hi2), $lo) - $lo) / $span;
                $gray[$y][$x] = max(0.0, min(1.0, ($v - $mean) * 1.15 + $mean));
            }
        }
        $this->gray = $gray;
        $this->rgb = $rgb;

        // Texture dithérée à la résolution des sous-pixels
        $this->dith = self::dither($gray, $dithering);

        // Moyennes par module (luminance + couleur), pour le pixel art et
        // la couleur des modules pleins
        $n = $this->n;
        $s = self::S;
        $moduleMu = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $sg = 0.0;
                $sr = 0.0;
                $sgc = 0.0;
                $sb = 0.0;
                for ($y = 0; $y < $s; $y++) {
                    for ($x = 0; $x < $s; $x++) {
                        $sg += $gray[$r * $s + $y][$c * $s + $x];
                        [$pr, $pg, $pb] = $rgb[$r * $s + $y][$c * $s + $x];
                        $sr += $pr;
                        $sgc += $pg;
                        $sb += $pb;
                    }
                }
                $k = $s * $s;
                $moduleMu[$r][$c] = $sg / $k;
                $this->moduleRgb[$r][$c] = [$sr / $k, $sgc / $k, $sb / $k];
            }
        }

        if ($moduleDither) {
            // Pixel art : dithering à la résolution des modules — le QR
            // entier est l'image, chaque module est un pixel. La confiance
            // reste la distance au seuil : les modules ambigus coûtent
            // moins cher à sacrifier au solveur.
            $this->target = self::dither($moduleMu, $dithering);
            for ($r = 0; $r < $n; $r++) {
                for ($c = 0; $c < $n; $c++) {
                    $this->conf[$r][$c] = abs($moduleMu[$r][$c] - 0.5) * 2;
                }
            }

            return;
        }

        // Cible + confiance par module (coeur 3x3 du module) — halftone
        $m = intdiv($s, 2);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $sum = 0.0;
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $sum += $gray[$r * $s + $m + $dy][$c * $s + $m + $dx];
                    }
                }
                $mu = $sum / 9.0;
                $this->target[$r][$c] = $mu < 0.5 ? 1 : 0;
                $this->conf[$r][$c] = abs($mu - 0.5) * 2;
            }
        }
    }

    /**
     * Binarise une grille carrée de luminances selon l'algorithme choisi.
     *
     * @param  float[][]  $lum  luminances 0..1
     * @return int[][] 1 = noir
     */
    private static function dither(array $lum, Dithering $mode): array
    {
        $n = count($lum);
        $out = [];
        $kernel = $mode->kernel();
        if ($kernel !== null) {
            [$kern, $div] = $kernel;
            $a = $lum;
            for ($y = 0; $y < $n; $y++) {
                for ($x = 0; $x < $n; $x++) {
                    $new = $a[$y][$x] > 0.5 ? 1.0 : 0.0;
                    $out[$y][$x] = 1 - (int) $new;
                    $err = ($a[$y][$x] - $new) / $div;
                    foreach ($kern as [$dy, $dx, $w]) {
                        $ny = $y + $dy;
                        $nx = $x + $dx;
                        if ($ny < $n && $nx >= 0 && $nx < $n) {
                            $a[$ny][$nx] += $err * $w;
                        }
                    }
                }
            }

            return $out;
        }

        // Bayer 8x8 (Ordered) ou seuil fixe (None)
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $t = $mode === Dithering::Ordered
                    ? (Dithering::BAYER8[$y % 8][$x % 8] + 0.5) / 64.0
                    : 0.5;
                $out[$y][$x] = $lum[$y][$x] > $t ? 0 : 1;
            }
        }

        return $out;
    }
}
