<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/**
 * Géométrie QR version 10, ECC L : modules de fonction, ordre zigzag des
 * modules de données, entrelacement des codewords.
 */
final class QArtSpec
{
    public const N              = 57;
    public const HEADER_BITS    = 20;              // mode byte (4) + longueur (16)
    public const DATA_CODEWORDS = 274;
    public const BLOCK_SIZES    = [68, 68, 69, 69];
    public const ECC_PER_BLOCK  = 18;
    public const CAPACITY       = 271;
    public const ALIGN          = [6, 28, 50];

    /** @var bool[][] modules de fonction (finders, timing, alignement, format, version) */
    public array $fmap;

    /** @var array<int, array{0:int,1:int}> ordre zigzag des modules de données */
    public array $zigzag;

    public function __construct()
    {
        $n = self::N;
        $f = array_fill(0, $n, array_fill(0, $n, false));
        $mark = function (int $r0, int $r1, int $c0, int $c1) use (&$f): void {
            for ($r = $r0; $r < $r1; $r++) {
                for ($c = $c0; $c < $c1; $c++) {
                    $f[$r][$c] = true;
                }
            }
        };
        $mark(0, 9, 0, 9);
        $mark(0, 9, $n - 8, $n);
        $mark($n - 8, $n, 0, 9);
        for ($i = 0; $i < $n; $i++) {
            $f[6][$i] = true;
            $f[$i][6] = true;
        }
        foreach (self::ALIGN as $r) {
            foreach (self::ALIGN as $c) {
                if (($r < 9 && $c < 9) || ($r < 9 && $c > $n - 9) || ($r > $n - 9 && $c < 9)) {
                    continue;
                }
                $mark($r - 2, $r + 3, $c - 2, $c + 3);
            }
        }
        $mark(8, 9, 0, 9);
        $mark(0, 9, 8, 9);
        $mark(8, 9, $n - 8, $n);
        $mark($n - 8, $n, 8, 9);
        $mark(0, 6, $n - 11, $n - 8);   // version info
        $mark($n - 11, $n - 8, 0, 6);
        $this->fmap = $f;

        // Zigzag en serpentin depuis le coin bas-droit, saut de la colonne 6
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
                    if (!$f[$r][$c]) {
                        $order[] = [$r, $c];
                    }
                }
            }
            $up = !$up;
            $col -= 2;
        }
        $expected = (self::DATA_CODEWORDS + 4 * self::ECC_PER_BLOCK) * 8;
        if (count($order) !== $expected) {
            throw new Exception\QArtException('zigzag: '.count($order)." != $expected");
        }
        $this->zigzag = $order;
    }

    /** Position entrelacée d'un codeword de données (0..273). */
    public static function interleave(int $p): int
    {
        $blk = 0;
        $off = $p;
        foreach (self::BLOCK_SIZES as $i => $size) {
            if ($off < $size) {
                $blk = $i;
                break;
            }
            $off -= $size;
        }

        return $off < 68 ? 4 * $off + $blk : 4 * 68 + ($blk - 2);
    }

    /** @return array<int, array{0:int,1:int}> coordonnées des 8 modules du caractère $k de l'URL (MSB d'abord) */
    public function charCoords(int $k): array
    {
        $out = [];
        for ($b = 0; $b < 8; $b++) {
            $s = self::HEADER_BITS + 8 * $k + $b;
            $cw = intdiv($s, 8);
            $f = self::interleave($cw) * 8 + ($s % 8);
            $out[] = $this->zigzag[$f];
        }

        return $out;
    }

    /** @return array<int, array{0:int, 1:array<int, array{0:int,1:int}>}> modules des 346 codewords (données puis ECC), avec leur bloc */
    public function codewordModules(): array
    {
        $out = [];
        for ($p = 0; $p < self::DATA_CODEWORDS; $p++) {
            $blk = 0;
            $off = $p;
            foreach (self::BLOCK_SIZES as $i => $size) {
                if ($off < $size) {
                    $blk = $i;
                    break;
                }
                $off -= $size;
            }
            $q = self::interleave($p);
            $out[] = [$blk, array_map(fn ($b) => $this->zigzag[$q * 8 + $b], range(0, 7))];
        }
        for ($r = 0; $r < self::ECC_PER_BLOCK; $r++) {
            for ($blk = 0; $blk < 4; $blk++) {
                $q = self::DATA_CODEWORDS + 4 * $r + $blk;
                $out[] = [$blk, array_map(fn ($b) => $this->zigzag[$q * 8 + $b], range(0, 7))];
            }
        }

        return $out;
    }
}
