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

    /** @var string[] avertissements non bloquants (upscale, faible contraste…) */
    public array $warnings = [];

    public static function fromFile(string $path, int $modules = 57): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new ImageException("image illisible: $path");
        }

        return self::fromString($data, $modules);
    }

    public static function fromString(string $data, int $modules = 57): self
    {
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            throw new ImageException('format d\'image non reconnu ou fichier corrompu');
        }

        return new self($src, $modules);
    }

    public function __construct(GdImage $src, int $modules = 57)
    {
        $this->n = $modules;
        $this->hi = $modules * self::S;
        $hi = $this->hi;

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        if ($side < 1) {
            throw new ImageException('image vide');
        }
        if ($side < $hi) {
            $this->warnings[] = sprintf(
                'image %dx%d plus petite que %d px de côté : agrandie, le rendu peut être flou',
                $w, $h, $hi
            );
        }

        // Aplatissement palette/alpha sur fond blanc
        $flat = imagecreatetruecolor($w, $h);
        imagefilledrectangle($flat, 0, 0, $w - 1, $h - 1, 0xFFFFFF);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);

        // Recadrage carré centré + redimensionnement à la grille de sous-pixels
        $sq = imagecreatetruecolor($hi, $hi);
        imagecopyresampled(
            $sq, $flat, 0, 0,
            intdiv($w - $side, 2), intdiv($h - $side, 2),
            $hi, $hi, $side, $side
        );

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

        // Dithering Atkinson
        $a = $gray;
        $dith = [];
        $kern = [[0, 1], [0, 2], [1, -1], [1, 0], [1, 1], [2, 0]];
        for ($y = 0; $y < $hi; $y++) {
            for ($x = 0; $x < $hi; $x++) {
                $new = $a[$y][$x] > 0.5 ? 1.0 : 0.0;
                $dith[$y][$x] = 1 - (int) $new;          // 1 = noir
                $err = ($a[$y][$x] - $new) / 8.0;
                foreach ($kern as [$dy, $dx]) {
                    $ny = $y + $dy;
                    $nx = $x + $dx;
                    if ($ny < $hi && $nx >= 0 && $nx < $hi) {
                        $a[$ny][$nx] += $err;
                    }
                }
            }
        }
        $this->dith = $dith;

        // Cible + confiance par module (coeur 3x3 du module)
        $n = $this->n;
        $s = self::S;
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
}
