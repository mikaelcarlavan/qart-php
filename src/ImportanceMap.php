<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Exception\QArtException;

/**
 * Carte d'importance : zones protégées garanties au plus fidèle.
 *
 * Les modules des zones protégées passent en tête de l'ordre de pivot du
 * solveur (ils consomment les degrés de liberté en premier), puis le budget
 * d'erreur privilégie les retardataires. Les coordonnées sont des fractions
 * (0..1) du carré recadré centré — le même recadrage que ImagePipeline.
 */
final class ImportanceMap
{
    /** Poids d'un module protégé dans le tri du budget d'erreur. */
    public const PROTECT_WEIGHT = 1000.0;

    /** @var array<array{0:float,1:float,2:float,3:float}> [x, y, w, h] en fractions */
    private array $zones = [];

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
