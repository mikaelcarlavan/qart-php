<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use GdImage;
use SqrArt\QArt\Exception\ImageException;
use SqrArt\QArt\Exception\QArtException;

/**
 * Carte d'importance : zones protégées garanties au plus fidèle, et carte
 * de poids peinte par l'utilisateur.
 *
 * Les modules des zones protégées passent en tête de l'ordre de pivot du
 * solveur (ils consomment les degrés de liberté en premier), puis le budget
 * d'erreur privilégie les retardataires. La carte peinte (masque image)
 * pondère la priorité des autres modules — un boost, pas une garantie.
 * Les coordonnées sont des fractions (0..1) du carré recadré — le même
 * recadrage que ImagePipeline.
 */
final class ImportanceMap
{
    /** Poids d'un module protégé dans le tri du budget d'erreur. */
    public const PROTECT_WEIGHT = 1000.0;

    /** Multiplicateur maximal de priorité d'un module peint à 100 %. */
    public const PAINT_BOOST = 2.0;

    /** @var array<array{0:float,1:float,2:float,3:float}> [x, y, w, h] en fractions */
    private array $zones = [];

    private ?GdImage $paintMask = null;

    public function protect(float $x, float $y, float $w, float $h): self
    {
        if ($x < 0 || $y < 0 || $x >= 1 || $y >= 1 || $w <= 0 || $h <= 0) {
            throw new QArtException(
                "zone protégée invalide ($x, $y, $w, $h) : fractions attendues, origine dans [0,1[ et taille > 0"
            );
        }
        $this->zones[] = [$x, $y, min($w, 1 - $x), min($h, 1 - $y)];

        return $this;
    }

    public function hasZones(): bool
    {
        return $this->zones !== [];
    }

    /**
     * Carte peinte : image (PNG/JPEG/WebP) alignée sur le carré recadré,
     * luminance sur fond noir = importance (blanc = maximum). C'est le
     * masque dessiné au pinceau dans l'éditeur.
     */
    public function paint(string $imageData): self
    {
        $mask = @imagecreatefromstring($imageData);
        if ($mask === false) {
            throw new ImageException('carte d\'importance illisible (image attendue)');
        }
        $this->paintMask = $mask;

        return $this;
    }

    public function hasPaint(): bool
    {
        return $this->paintMask !== null;
    }

    /**
     * Poids par module (0..1) : masque peint rééchantillonné sur la grille,
     * aplati sur fond noir (les zones non peintes pèsent 0).
     *
     * @return float[][] n x n
     */
    public function moduleWeights(QArtSpec $spec): array
    {
        $n = $spec->n;
        $weights = array_fill(0, $n, array_fill(0, $n, 0.0));
        if ($this->paintMask === null) {
            return $weights;
        }

        $w = imagesx($this->paintMask);
        $h = imagesy($this->paintMask);
        $small = imagecreatetruecolor($n, $n);
        imagefilledrectangle($small, 0, 0, $n - 1, $n - 1, 0x000000);
        imagecopyresampled($small, $this->paintMask, 0, 0, 0, 0, $n, $n, $w, $h);

        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $px = imagecolorat($small, $c, $r);
                $weights[$r][$c] = (0.299 * (($px >> 16) & 0xFF)
                    + 0.587 * (($px >> 8) & 0xFF)
                    + 0.114 * ($px & 0xFF)) / 255.0;
            }
        }

        return $weights;
    }

    /**
     * Projette les zones sur la grille de modules : un module est protégé
     * dès que son emprise intersecte une zone.
     *
     * @return bool[][] masque n x n
     */
    public function moduleMask(QArtSpec $spec): array
    {
        $n = $spec->n;
        $mask = array_fill(0, $n, array_fill(0, $n, false));
        foreach ($this->zones as [$x, $y, $w, $h]) {
            $c0 = max(0, (int) floor($x * $n));
            $c1 = min($n - 1, (int) ceil(($x + $w) * $n) - 1);
            $r0 = max(0, (int) floor($y * $n));
            $r1 = min($n - 1, (int) ceil(($y + $h) * $n) - 1);
            for ($r = $r0; $r <= $r1; $r++) {
                for ($c = $c0; $c <= $c1; $c++) {
                    $mask[$r][$c] = true;
                }
            }
        }

        return $mask;
    }
}
