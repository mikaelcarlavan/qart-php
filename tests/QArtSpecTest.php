<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Bits;
use SqrArt\QArt\Oracle;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\Random\SeededRandom;

final class QArtSpecTest extends TestCase
{
    /** Formules officielles des 8 masques QR. */
    private static function maskAt(int $mask, int $i, int $j): bool
    {
        return match ($mask) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => ($i * $j) % 2 + ($i * $j) % 3 === 0,
            6 => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
            7 => (($i + $j) % 2 + ($i * $j) % 3) % 2 === 0,
        };
    }

    public function testZigzagCoversAllDataModules(): void
    {
        $spec = new QArtSpec();
        $this->assertCount(2768, $spec->zigzag);

        // aucun doublon, aucun module de fonction
        $seen = [];
        foreach ($spec->zigzag as [$r, $c]) {
            $this->assertFalse($spec->fmap[$r][$c], "module de fonction ($r,$c) dans le zigzag");
            $seen["$r-$c"] = true;
        }
        $this->assertCount(2768, $seen);
    }

    public function testInterleaveIsABijection(): void
    {
        $positions = [];
        for ($p = 0; $p < QArtSpec::DATA_CODEWORDS; $p++) {
            $positions[] = QArtSpec::interleave($p);
        }
        sort($positions);
        $this->assertSame(range(0, QArtSpec::DATA_CODEWORDS - 1), $positions);
    }

    /**
     * Chaque bit de chaque caractère de l'URL doit se retrouver au module
     * prédit par charCoords() dans le rendu réel, pour les 8 masques.
     */
    public function testCharCoordsMatchOracleForAllMasks(): void
    {
        $spec = new QArtSpec();
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $rng = new SeededRandom(1);
        $url = 'https://sqr.art/';
        for ($i = strlen($url); $i < QArtSpec::CAPACITY; $i++) {
            $url .= $alpha[$rng->int(0, 63)];
        }

        $n = QArtSpec::N;
        foreach (range(0, 7) as $mask) {
            $m = Oracle::render($url, $mask);
            $bad = 0;
            for ($k = 0; $k < QArtSpec::CAPACITY; $k++) {
                $v = ord($url[$k]);
                $coords = $spec->charCoords($k);
                for ($b = 0; $b < 8; $b++) {
                    [$r, $c] = $coords[$b];
                    $bit = ($v >> (7 - $b)) & 1;
                    $expected = $bit ^ (self::maskAt($mask, $r, $c) ? 1 : 0);
                    if (Bits::get($m, $r * $n + $c) !== $expected) {
                        $bad++;
                    }
                }
            }
            $this->assertSame(0, $bad, "masque $mask : $bad erreurs de mapping");
        }
    }
}
