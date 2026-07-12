<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Native\Gf2;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\Random\SeededRandom;
use SqrArt\QArt\Solver;
use SqrArt\QArt\UrlMode;

final class NativeGf2Test extends TestCase
{
    /**
     * LE test de ce chantier : sur de vraies colonnes (sondées via l'oracle),
     * l'élimination native doit être identique octet pour octet au PHP pur —
     * mêmes pivots, mêmes colonnes, mêmes compositions.
     */
    public function test_native_elimination_matches_pure_php(): void
    {
        if (! Gf2::available()) {
            $this->markTestSkipped('libqart_gf2 indisponible (cargo build --release dans native/, ffi.enable=1)');
        }

        $spec = new QArtSpec(5);
        $php = new Solver($spec, 'https://sqr.art/', new SeededRandom(3), UrlMode::Full);
        $php->buildGenerator();
        $native = new Solver($spec, 'https://sqr.art/', new SeededRandom(3), UrlMode::Full);
        $native->buildGenerator();

        // ordre d'importance arbitraire mais adversarial : positions des
        // modules de données mélangées de façon déterministe
        $order = [];
        foreach ($spec->zigzag as [$r, $c]) {
            $order[] = $r * $spec->n + $c;
        }
        $rng = new SeededRandom(99);
        for ($i = count($order) - 1; $i > 0; $i--) {
            $j = $rng->int(0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }

        $php->eliminate($order, native: false);
        $native->eliminate($order, native: true);

        $this->assertSame($php->pivots, $native->pivots, 'pivots divergents');
        $this->assertSame($php->cols, $native->cols, 'colonnes divergentes');
        $this->assertSame($php->comp, $native->comp, 'compositions divergentes');
        $this->assertNotEmpty($php->pivots);
    }

    /** En mode Short aussi (variables 8 bits, autre géométrie de colonnes). */
    public function test_native_elimination_matches_pure_php_in_short_mode(): void
    {
        if (! Gf2::available()) {
            $this->markTestSkipped('libqart_gf2 indisponible');
        }

        $spec = new QArtSpec(5);
        $php = new Solver($spec, 'https://sqr.art/', new SeededRandom(4), UrlMode::Short);
        $php->buildGenerator();
        $native = new Solver($spec, 'https://sqr.art/', new SeededRandom(4), UrlMode::Short);
        $native->buildGenerator();

        $order = [];
        foreach ($spec->zigzag as [$r, $c]) {
            $order[] = $r * $spec->n + $c;
        }

        $php->eliminate($order, native: false);
        $native->eliminate($order, native: true);

        $this->assertSame($php->pivots, $native->pivots);
        $this->assertSame($php->cols, $native->cols);
        $this->assertSame($php->comp, $native->comp);
    }

    public function test_library_detection_does_not_throw(): void
    {
        Gf2::reset();
        $this->assertIsBool(Gf2::available());
        Gf2::reset();
    }
}
