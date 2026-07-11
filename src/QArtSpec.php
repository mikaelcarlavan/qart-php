<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Version;
use SqrArt\QArt\Exception\QArtException;

/**
 * Géométrie QR pour une version donnée (1..40), ECC L : modules de fonction,
 * ordre zigzag des modules de données, entrelacement des codewords,
 * capacité en mode byte à pleine charge.
 *
 * Les tables par version (structure de blocs Reed-Solomon, centres des
 * motifs d'alignement) sont dérivées de chillerlan — la même librairie qui
 * sert d'oracle d'encodage — pour garantir la cohérence sans retranscription.
 */
final class QArtSpec
{
    public const DEFAULT_VERSION = 10;

    public readonly int $version;

    /** Côté de la matrice en modules (17 + 4 * version). */
    public readonly int $n;

    /** Mode byte (4 bits) + indicateur de longueur (8 bits v1-9, 16 bits v10-40). */
    public readonly int $headerBits;

    /** Nombre de caractères byte à pleine charge (terminateur 4 bits). */
    public readonly int $capacity;

    public readonly int $dataCodewords;

    /** @var int[] tailles des blocs de données, dans l'ordre du standard */
    public readonly array $blockSizes;

    public readonly int $eccPerBlock;

    /** Bits résiduels du zigzag après le dernier codeword (0..7), non contrôlables. */
    public readonly int $remainderBits;

    /** @var bool[][] modules de fonction (finders, timing, alignement, format, version) */
    public array $fmap;

    /** @var array<int, array{0:int,1:int}> ordre zigzag de TOUS les modules de données (remainder inclus) */
    public array $zigzag;

    /** Nombre de blocs de taille minimale (les blocs longs sont en fin de liste). */
    private int $shortBlocks;

    private int $minBlockSize;

    public function __construct(int $version = self::DEFAULT_VERSION)
    {
        if ($version < 1 || $version > 40) {
            throw new QArtException("version QR invalide: $version (1..40)");
        }
        $this->version = $version;

        $meta = new Version($version);
        $this->n = $meta->getDimension();
        [$eccPerBlock, $groups] = $meta->getRSBlocks(new EccLevel(EccLevel::L));
        $this->eccPerBlock = $eccPerBlock;
        $sizes = [];
        foreach ($groups as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $sizes[] = $size;
            }
        }
        $this->blockSizes = $sizes;
        $this->dataCodewords = array_sum($sizes);
        $this->minBlockSize = min($sizes);
        $this->shortBlocks = count(array_filter($sizes, fn ($s) => $s === $this->minBlockSize));

        $this->headerBits = 4 + ($version <= 9 ? 8 : 16);
        $this->capacity = intdiv(8 * $this->dataCodewords - $this->headerBits, 8);

        $this->buildFunctionMap($meta);
        $this->buildZigzag();

        $total = $this->dataCodewords + count($sizes) * $eccPerBlock;
        $remainder = count($this->zigzag) - 8 * $total;
        if ($remainder < 0 || $remainder > 7) {
            throw new QArtException(
                "v$version: zigzag incohérent (".count($this->zigzag)." modules pour $total codewords)"
            );
        }
        $this->remainderBits = $remainder;
    }

    private function buildFunctionMap(Version $meta): void
    {
        $n = $this->n;
        $f = array_fill(0, $n, array_fill(0, $n, false));
        $mark = function (int $r0, int $r1, int $c0, int $c1) use (&$f): void {
            for ($r = $r0; $r < $r1; $r++) {
                for ($c = $c0; $c < $c1; $c++) {
                    $f[$r][$c] = true;
                }
            }
        };
        // finders + séparateurs
        $mark(0, 9, 0, 9);
        $mark(0, 9, $n - 8, $n);
        $mark($n - 8, $n, 0, 9);
        // timing
        for ($i = 0; $i < $n; $i++) {
            $f[6][$i] = true;
            $f[$i][6] = true;
        }
        // alignement (centres chillerlan), hors zones finder
        $align = $meta->getAlignmentPattern();
        foreach ($align as $r) {
            foreach ($align as $c) {
                if (($r < 9 && $c < 9) || ($r < 9 && $c > $n - 9) || ($r > $n - 9 && $c < 9)) {
                    continue;
                }
                $mark($r - 2, $r + 3, $c - 2, $c + 3);
            }
        }
        // format info (le module sombre fixe est couvert par la zone bas-gauche)
        $mark(8, 9, 0, 9);
        $mark(0, 9, 8, 9);
        $mark(8, 9, $n - 8, $n);
        $mark($n - 8, $n, 8, 9);
        // version info (v7+)
        if ($this->version >= 7) {
            $mark(0, 6, $n - 11, $n - 8);
            $mark($n - 11, $n - 8, 0, 6);
        }
        $this->fmap = $f;
    }

    private function buildZigzag(): void
    {
        $n = $this->n;
        $order = [];
        $col = $n - 1;
        $up = true;
        while ($col > 0) {
            if ($col === 6) {
                $col--;
            }
            $rows = $up ? range($n - 1, 0) : range(0, $n - 1);
            foreach ($rows as $r) {
                foreach ([$col, $col - 1] as $c) {
                    if (! $this->fmap[$r][$c]) {
                        $order[] = [$r, $c];
                    }
                }
            }
            $up = ! $up;
            $col -= 2;
        }
        $this->zigzag = $order;
    }

    /** Position entrelacée d'un codeword de données (0..dataCodewords-1). */
    public function interleave(int $p): int
    {
        $blk = 0;
        $off = $p;
        foreach ($this->blockSizes as $i => $size) {
            if ($off < $size) {
                $blk = $i;
                break;
            }
            $off -= $size;
        }
        $nb = count($this->blockSizes);

        return $off < $this->minBlockSize
            ? $nb * $off + $blk
            : $nb * $this->minBlockSize + ($blk - $this->shortBlocks);
    }

    /** @return array<int, array{0:int,1:int}> coordonnées des 8 modules du caractère $k de l'URL (MSB d'abord) */
    public function charCoords(int $k): array
    {
        $out = [];
        for ($b = 0; $b < 8; $b++) {
            $s = $this->headerBits + 8 * $k + $b;
            $cw = intdiv($s, 8);
            $f = $this->interleave($cw) * 8 + ($s % 8);
            $out[] = $this->zigzag[$f];
        }

        return $out;
    }

    /** @return array<int, array{0:int, 1:array<int, array{0:int,1:int}>}> modules des codewords (données puis ECC), avec leur bloc */
    public function codewordModules(): array
    {
        $nb = count($this->blockSizes);
        $out = [];
        for ($p = 0; $p < $this->dataCodewords; $p++) {
            $blk = 0;
            $off = $p;
            foreach ($this->blockSizes as $i => $size) {
                if ($off < $size) {
                    $blk = $i;
                    break;
                }
                $off -= $size;
            }
            $q = $this->interleave($p);
            $out[] = [$blk, array_map(fn ($b) => $this->zigzag[$q * 8 + $b], range(0, 7))];
        }
        for ($r = 0; $r < $this->eccPerBlock; $r++) {
            for ($blk = 0; $blk < $nb; $blk++) {
                $q = $this->dataCodewords + $nb * $r + $blk;
                $out[] = [$blk, array_map(fn ($b) => $this->zigzag[$q * 8 + $b], range(0, 7))];
            }
        }

        return $out;
    }
}
