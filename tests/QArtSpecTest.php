<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Bits;
use SqrArt\QArt\Ecc;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\Oracle;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\Random\SeededRandom;

final class QArtSpecTest extends TestCase
{
    /**
     * Versions couvrant les variantes structurelles : header 8 vs 16 bits,
     * version info absente/présente, bits résiduels 0/3/4/7, blocs de
     * tailles inégales, 1 seul bloc (v2).
     *
     * @return array<string, array{0:int,1:int}> [version, remainder attendu]
     */
    public static function versions(): array
    {
        return [
            'v2 (1 bloc, header 8b, rem 7)' => [2, 7],
            'v5 (blocs inégaux, header 8b)' => [5, 7],
            'v7 (version info, rem 0)' => [7, 0],
            'v10 (référence historique)' => [10, 0],
            'v14 (rem 3)' => [14, 3],
            'v21 (rem 4)' => [21, 4],
        ];
    }

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

    #[DataProvider('versions')]
    public function test_zigzag_covers_all_data_modules(int $version, int $expectedRemainder): void
    {
        $spec = new QArtSpec($version);
        $total = $spec->dataCodewords + count($spec->blockSizes) * $spec->eccPerBlock;

        $this->assertSame($expectedRemainder, $spec->remainderBits);
        $this->assertCount(8 * $total + $expectedRemainder, $spec->zigzag);
        $this->assertSame(17 + 4 * $version, $spec->n);

        // aucun doublon, aucun module de fonction
        $seen = [];
        foreach ($spec->zigzag as [$r, $c]) {
            $this->assertFalse($spec->fmap[$r][$c], "v$version: module de fonction ($r,$c) dans le zigzag");
            $seen["$r-$c"] = true;
        }
        $this->assertCount(count($spec->zigzag), $seen);
    }

    #[DataProvider('versions')]
    public function test_interleave_is_a_bijection(int $version): void
    {
        $spec = new QArtSpec($version);
        $positions = [];
        for ($p = 0; $p < $spec->dataCodewords; $p++) {
            $positions[] = $spec->interleave($p);
        }
        sort($positions);
        $this->assertSame(range(0, $spec->dataCodewords - 1), $positions);
    }

    /**
     * Chaque bit de chaque caractère de l'URL doit se retrouver au module
     * prédit par charCoords() dans le rendu réel, pour les 8 masques.
     * C'est LE test qui valide géométrie, tables et entrelacement.
     */
    #[DataProvider('versions')]
    public function test_char_coords_match_oracle_for_all_masks(int $version): void
    {
        $spec = new QArtSpec($version);
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $rng = new SeededRandom($version);
        $url = 'https://sqr.art/';
        for ($i = strlen($url); $i < $spec->capacity; $i++) {
            $url .= $alpha[$rng->int(0, 63)];
        }

        $n = $spec->n;
        foreach (range(0, 7) as $mask) {
            $m = Oracle::render($url, $mask, $version);
            $bad = 0;
            for ($k = 0; $k < $spec->capacity; $k++) {
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
            $this->assertSame(0, $bad, "v$version masque $mask : $bad erreurs de mapping");
        }
    }

    /** @return array<string, array{0:int,1:Ecc}> combinaisons couvrant les 4 niveaux */
    public static function eccLevels(): array
    {
        return [
            'v10 M' => [10, Ecc::M],
            'v10 Q' => [10, Ecc::Q],
            'v10 H' => [10, Ecc::H],
            'v5 H (header 8b)' => [5, Ecc::H],
            'v14 M (remainder 3)' => [14, Ecc::M],
        ];
    }

    #[DataProvider('eccLevels')]
    public function test_char_coords_match_oracle_for_all_ecc_levels(int $version, Ecc $ecc): void
    {
        $spec = new QArtSpec($version, $ecc);
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $rng = new SeededRandom($version * 100 + ord($ecc->value));
        $url = 'https://sqr.art/';
        for ($i = strlen($url); $i < $spec->capacity; $i++) {
            $url .= $alpha[$rng->int(0, 63)];
        }

        $n = $spec->n;
        foreach (range(0, 7) as $mask) {
            $m = Oracle::render($url, $mask, $version, $ecc);
            $bad = 0;
            for ($k = 0; $k < $spec->capacity; $k++) {
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
            $this->assertSame(0, $bad, "v$version-{$ecc->value} masque $mask : $bad erreurs de mapping");
        }
    }

    public function test_capacity_shrinks_with_ecc_level(): void
    {
        $caps = array_map(fn (Ecc $e) => (new QArtSpec(10, $e))->capacity, [Ecc::L, Ecc::M, Ecc::Q, Ecc::H]);
        $this->assertSame(271, $caps[0]);
        // capacité strictement décroissante, budget ECC croissant
        $this->assertTrue($caps[0] > $caps[1] && $caps[1] > $caps[2] && $caps[2] > $caps[3], json_encode($caps));
        $this->assertGreaterThan(
            (new QArtSpec(10, Ecc::L))->eccPerBlock,
            (new QArtSpec(10, Ecc::H))->eccPerBlock
        );
    }

    public function test_rejects_invalid_versions(): void
    {
        $this->expectException(QArtException::class);
        new QArtSpec(41);
    }

    public function test_capacity_known_values(): void
    {
        $this->assertSame(17, (new QArtSpec(1))->capacity);
        $this->assertSame(106, (new QArtSpec(5))->capacity);
        $this->assertSame(271, (new QArtSpec(10))->capacity);
        $this->assertSame(2953, (new QArtSpec(40))->capacity);
    }
}
