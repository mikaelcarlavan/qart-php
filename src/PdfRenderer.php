<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Exception\QArtException;

/**
 * Export PDF vectoriel print-ready, sans dépendance : le document est
 * assemblé à la main (PDF 1.4, page unique, flux FlateDecode). Mêmes
 * règles visuelles que SvgRenderer — texture fusionnée par plages, points
 * contraints en luminance, finders arrondis — mais dimensionné en
 * millimètres pour l'impression (quiet zone comprise).
 *
 * Couleurs en RVB : suffisant pour les flux d'impression courants (la
 * conversion CMJN est faite par l'imprimeur). Taille physique minimale
 * recommandée : ~40 mm en halftone.
 */
final class PdfRenderer
{
    /** Même quantification des couleurs de texture que le SVG. */
    private const QUANT = 8;

    /** Côté de page par défaut, quiet zone comprise. */
    public const DEFAULT_SIZE_MM = 100.0;

    /** Facteur de contrôle des arcs de Bézier approximant un quart de cercle. */
    private const KAPPA = 0.552284749831;

    /**
     * Chevauchement des rectangles adjacents (en unités sous-pixel, soit
     * ~0.02 mm) : sans lui, l'anti-aliasing des visualiseurs et de
     * Ghostscript laisse une couture claire entre fills mitoyens — assez
     * pour gêner la détection des finders sur un rendu écran.
     */
    private const BLEED = 0.08;

    /** @param int[][] $matrix modules finaux (après budget d'erreur) */
    public static function colorHalftone(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        RenderProfile $profile,
        float $sizeMm = self::DEFAULT_SIZE_MM,
    ): string {
        $n = $spec->n;
        $s = ImagePipeline::S;
        $d = ImagePipeline::D;
        $off = intdiv($s - $d, 2);
        $border = $profile->borderModules * $s;
        $size = $n * $s + 2 * $border;
        $hi = $img->hi;

        $ops = '';
        $cur = null;

        // 1. Texture dithérée fusionnée par plages horizontales
        for ($y = 0; $y < $hi; $y++) {
            $runColor = null;
            $runStart = 0;
            $flush = function (int $end) use (&$runColor, &$runStart, &$ops, &$cur, $y, $border): void {
                if ($runColor !== null) {
                    self::fill($ops, $cur, $runColor);
                    $ops .= self::rect($border + $runStart, $border + $y, $end - $runStart, 1);
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
                $color = self::quant(Luma::apply($img->rgb[$y][$x], $dark ? $profile->lDark : $profile->lLight));
                if ($color !== $runColor) {
                    $flush($x);
                    $runColor = $color;
                    $runStart = $x;
                }
            }
            $flush($hi);
        }

        // 2. Points de données par-dessus la texture
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
                self::fill($ops, $cur, self::quant(Luma::apply($rgb, $bit ? $profile->lDotDark : $profile->lDotLight)));
                $ops .= match ($profile->dotShape) {
                    DotShape::Square => self::rect($cx - $half, $cy - $half, $d, $d),
                    DotShape::Round => self::circle($cx, $cy, $half),
                    DotShape::Diamond => self::diamond($cx, $cy, $half),
                };
            }
        }

        // 3. Modules de fonction + finders
        $ops .= self::functionModules($spec, $matrix, $profile, $s, $border, $cur);

        return self::document($ops, $size, $sizeMm);
    }

    /** @param int[][] $matrix modules finaux (après budget d'erreur) */
    public static function pixelArt(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        RenderProfile $profile,
        float $sizeMm = self::DEFAULT_SIZE_MM,
    ): string {
        $n = $spec->n;
        $s = ImagePipeline::S;
        $border = $profile->borderModules * $s;
        $size = $n * $s + 2 * $border;
        $rounded = $profile->finderShape === FinderShape::Rounded;
        $finderRgb = $profile->finderRgb();

        $ops = '';
        $cur = null;
        for ($r = 0; $r < $n; $r++) {
            $runColor = null;
            $runStart = 0;
            $flush = function (int $end) use (&$runColor, &$runStart, &$ops, &$cur, $r, $s, $border): void {
                if ($runColor !== null) {
                    self::fill($ops, $cur, $runColor);
                    $ops .= self::rect($border + $runStart * $s, $border + $r * $s, ($end - $runStart) * $s, $s);
                }
                $runColor = null;
            };
            for ($c = 0; $c < $n; $c++) {
                $dark = (bool) $matrix[$r][$c];
                if ($spec->fmap[$r][$c]) {
                    $inFinder = self::inFinder($r, $c, $n);
                    if ((! $dark) || ($rounded && $inFinder)) {
                        $flush($c);

                        continue;
                    }
                    // couleur exacte du finder (choix utilisateur)
                    $color = $inFinder ? $finderRgb : [0.0, 0.0, 0.0];
                } else {
                    $color = self::quant(Luma::apply(
                        $img->moduleRgb[$r][$c],
                        $dark ? $profile->lDotDark : $profile->lDotLight
                    ));
                }
                if ($color !== $runColor) {
                    $flush($c);
                    $runColor = $color;
                    $runStart = $c;
                }
            }
            $flush($n);
        }

        if ($rounded) {
            $ops .= self::roundedFinders($spec->n, $s, $border, $finderRgb, $cur);
        }

        return self::document($ops, $size, $sizeMm);
    }

    public static function toFile(
        ImagePipeline $img,
        QArtSpec $spec,
        array $matrix,
        string $out,
        RenderProfile $profile,
        float $sizeMm = self::DEFAULT_SIZE_MM,
    ): void {
        $pdf = $profile->mode === RenderMode::Module
            ? self::pixelArt($img, $spec, $matrix, $profile, $sizeMm)
            : self::colorHalftone($img, $spec, $matrix, $profile, $sizeMm);
        if (@file_put_contents($out, $pdf) === false) {
            throw new QArtException("écriture du PDF impossible: $out");
        }
    }

    /** Modules de fonction sombres + finders (mêmes règles que le SVG). */
    private static function functionModules(QArtSpec $spec, array $matrix, RenderProfile $profile, int $s, int $border, ?string &$cur): string
    {
        $n = $spec->n;
        $rounded = $profile->finderShape === FinderShape::Rounded;
        $finderRgb = $profile->finderRgb();
        $ops = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (! $spec->fmap[$r][$c] || ! $matrix[$r][$c]) {
                    continue;
                }
                $inFinder = self::inFinder($r, $c, $n);
                if ($rounded && $inFinder) {
                    continue;
                }
                self::fill($ops, $cur, $inFinder ? $finderRgb : [0.0, 0.0, 0.0]);
                $ops .= self::rect($border + $c * $s, $border + $r * $s, $s, $s);
            }
        }
        if ($rounded) {
            $ops .= self::roundedFinders($n, $s, $border, $finderRgb, $cur);
        }

        return $ops;
    }

    /** Anneau 7x7 + coeur 3x3 arrondis, sur fond déjà blanc. */
    private static function roundedFinders(int $n, int $s, int $border, array $finderRgb, ?string &$cur): string
    {
        $ops = '';
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$fr, $fc]) {
            $x = $border + $fc * $s;
            $y = $border + $fr * $s;
            self::fill($ops, $cur, $finderRgb);
            $ops .= self::roundedSquare($x, $y, 7 * $s, 7 * $s / 3);
            self::fill($ops, $cur, [1.0, 1.0, 1.0]);
            $ops .= self::roundedSquare($x + $s, $y + $s, 5 * $s, 5 * $s / 3.75);
            self::fill($ops, $cur, $finderRgb);
            $ops .= self::roundedSquare($x + 2 * $s, $y + 2 * $s, 3 * $s, (float) $s);
        }

        return $ops;
    }

    /** Assemble le document : une page carrée de $sizeMm, contenu en unités sous-pixel. */
    private static function document(string $ops, int $sizeUnits, float $sizeMm): string
    {
        if ($sizeMm < 1.0) {
            throw new QArtException("taille PDF invalide: {$sizeMm} mm");
        }
        $pt = $sizeMm * 72 / 25.4;
        $k = $pt / $sizeUnits;
        // repère PDF (origine bas-gauche, y vers le haut) -> repère image
        $content = sprintf("%.6f 0 0 -%.6f 0 %s cm\n", $k, $k, self::num($pt))
            ."1 1 1 rg\n".self::rect(0, 0, $sizeUnits, $sizeUnits)
            .$ops;
        $stream = gzcompress($content, 9);

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Contents 4 0 R /Resources << >> >>', self::num($pt), self::num($pt)),
            '<< /Length '.strlen($stream)." /Filter /FlateDecode >>\nstream\n".$stream."\nendstream",
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /** Change la couleur de remplissage si nécessaire. @param array{0:float,1:float,2:float} $rgb */
    private static function fill(string &$ops, ?string &$cur, array $rgb): void
    {
        $op = sprintf('%s %s %s rg', self::num($rgb[0]), self::num($rgb[1]), self::num($rgb[2]));
        if ($op !== $cur) {
            $ops .= $op."\n";
            $cur = $op;
        }
    }

    private static function rect(float $x, float $y, float $w, float $h): string
    {
        return sprintf(
            "%s %s %s %s re f\n",
            self::num($x), self::num($y),
            self::num($w + self::BLEED), self::num($h + self::BLEED),
        );
    }

    /** Cercle plein en quatre arcs de Bézier. */
    private static function circle(float $cx, float $cy, float $r): string
    {
        $k = self::KAPPA * $r;
        $n = self::num(...);

        return sprintf(
            "%s %s m\n%s %s %s %s %s %s c\n%s %s %s %s %s %s c\n%s %s %s %s %s %s c\n%s %s %s %s %s %s c\nf\n",
            $n($cx + $r), $n($cy),
            $n($cx + $r), $n($cy + $k), $n($cx + $k), $n($cy + $r), $n($cx), $n($cy + $r),
            $n($cx - $k), $n($cy + $r), $n($cx - $r), $n($cy + $k), $n($cx - $r), $n($cy),
            $n($cx - $r), $n($cy - $k), $n($cx - $k), $n($cy - $r), $n($cx), $n($cy - $r),
            $n($cx + $k), $n($cy - $r), $n($cx + $r), $n($cy - $k), $n($cx + $r), $n($cy),
        );
    }

    private static function diamond(float $cx, float $cy, float $half): string
    {
        $n = self::num(...);

        return sprintf(
            "%s %s m\n%s %s l\n%s %s l\n%s %s l\nf\n",
            $n($cx), $n($cy - $half),
            $n($cx + $half), $n($cy),
            $n($cx), $n($cy + $half),
            $n($cx - $half), $n($cy),
        );
    }

    /** Carré arrondi plein (lignes + arcs de coin). */
    private static function roundedSquare(float $x, float $y, float $side, float $radius): string
    {
        $x1 = $x + $side;
        $y1 = $y + $side;
        $c = $radius * (1 - self::KAPPA);
        $n = self::num(...);

        return sprintf(
            "%s %s m\n%s %s l\n%s %s %s %s %s %s c\n%s %s l\n%s %s %s %s %s %s c\n%s %s l\n%s %s %s %s %s %s c\n%s %s l\n%s %s %s %s %s %s c\nf\n",
            $n($x + $radius), $n($y),
            $n($x1 - $radius), $n($y),
            $n($x1 - $c), $n($y), $n($x1), $n($y + $c), $n($x1), $n($y + $radius),
            $n($x1), $n($y1 - $radius),
            $n($x1), $n($y1 - $c), $n($x1 - $c), $n($y1), $n($x1 - $radius), $n($y1),
            $n($x + $radius), $n($y1),
            $n($x + $c), $n($y1), $n($x), $n($y1 - $c), $n($x), $n($y1 - $radius),
            $n($x), $n($y + $radius),
            $n($x), $n($y + $c), $n($x + $c), $n($y), $n($x + $radius), $n($y),
        );
    }

    /** Les trois zones finder 7x7. */
    private static function inFinder(int $r, int $c, int $n): bool
    {
        return ($r < 7 && $c < 7)
            || ($r < 7 && $c >= $n - 7)
            || ($r >= $n - 7 && $c < 7);
    }

    /** Quantifie une couleur comme le SVG (plages plus longues). @param array{0:float,1:float,2:float} $rgb */
    private static function quant(array $rgb): array
    {
        $q = fn (float $v): float => min(255, round($v * 255 / self::QUANT) * self::QUANT) / 255.0;

        return [$q($rgb[0]), $q($rgb[1]), $q($rgb[2])];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
