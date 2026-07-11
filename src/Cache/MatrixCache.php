<?php

declare(strict_types=1);

namespace SqrArt\QArt\Cache;

/**
 * Cache de la matrice génératrice pré-élimination. Elle ne dépend que de la
 * longueur du préfixe (et de la version/ECC figées) : la calculer coûte
 * ~1200 rendus d'oracle (~4 s), la relire est quasi instantané.
 */
interface MatrixCache
{
    /** @return string[]|null colonnes (bitsets packés) ou null si absent */
    public function get(string $key): ?array;

    /** @param string[] $cols */
    public function set(string $key, array $cols): void;
}
