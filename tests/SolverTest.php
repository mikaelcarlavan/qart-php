<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\Random\SeededRandom;
use SqrArt\QArt\Solver;
use SqrArt\QArt\UrlMode;

final class SolverTest extends TestCase
{
    public function test_coset_alphabet_is32_url_safe_letters(): void
    {
        $coset = [Solver::OFFSET];
        foreach (Solver::BASIS as $v) {
            foreach ($coset as $c) {
                $coset[] = $c ^ $v;
            }
        }
        $this->assertCount(32, $coset);
        $this->assertCount(32, array_unique($coset));
        foreach ($coset as $c) {
            $char = chr($c);
            $this->assertMatchesRegularExpression(
                '/^[H-Wh-w]$/',
                $char,
                sprintf('caractère hors alphabet URL-safe: 0x%02X (%s)', $c, $char)
            );
        }
    }

    public function test_serial_uses_only_coset_alphabet_and_is_seedable(): void
    {
        $spec = new QArtSpec;
        $prefix = 'https://sqr.art/';
        $s1 = new Solver($spec, $prefix, new SeededRandom(42));
        $s2 = new Solver($spec, $prefix, new SeededRandom(42));
        $serial1 = substr($s1->baseUrl, strlen($prefix), Solver::SERIAL);
        $serial2 = substr($s2->baseUrl, strlen($prefix), Solver::SERIAL);

        $this->assertSame($serial1, $serial2, 'même graine => même série');
        $this->assertMatchesRegularExpression('/^[H-Wh-w]{8}$/', $serial1);

        $s1->reseedSerial();
        $reseeded = substr($s1->baseUrl, strlen($prefix), Solver::SERIAL);
        $this->assertNotSame($serial1, $reseeded, 'reseedSerial doit changer la série');
    }

    public function test_base_url_has_full_capacity(): void
    {
        $spec = new QArtSpec;
        $solver = new Solver($spec, 'https://sqr.art/', new SeededRandom(1));
        $this->assertSame($spec->capacity, strlen($solver->baseUrl));
        $this->assertStringStartsWith('https://sqr.art/', $solver->baseUrl);
    }

    public function test_rejects_oversized_prefix(): void
    {
        $this->expectException(QArtException::class);
        new Solver(new QArtSpec, str_repeat('a', 300), new SeededRandom(1));
    }

    public function test_rejects_non_ascii_prefix(): void
    {
        $this->expectException(QArtException::class);
        new Solver(new QArtSpec, 'https://sqr.art/é', new SeededRandom(1));
    }

    public function test_first_free_bit_is_byte_aligned_after_terminator(): void
    {
        // v10 : header 20 + 8*24 + 4 = 216, déjà aligné
        $this->assertSame(216, Solver::firstFreeBit(new QArtSpec(10), 16));
        // v5 : header 12 + 8*24 + 4 = 208, déjà aligné
        $this->assertSame(208, Solver::firstFreeBit(new QArtSpec(5), 16));
        // préfixe 15 : 20 + 8*23 + 4 = 208 -> aligné à 208
        $this->assertSame(208, Solver::firstFreeBit(new QArtSpec(10), 15));
        // préfixe 17 : 20 + 8*25 + 4 = 224
        $this->assertSame(224, Solver::firstFreeBit(new QArtSpec(10), 17));
    }

    public function test_short_mode_exposes_padding_bits_as_variables(): void
    {
        $spec = new QArtSpec(10);
        $solver = new Solver($spec, 'https://sqr.art/', new SeededRandom(42), UrlMode::Short);

        $this->assertSame(16 + Solver::SERIAL, strlen($solver->baseUrl));
        $this->assertStringStartsWith('https://sqr.art/', $solver->baseUrl);
        // bits libres : de 216 à header + 8*capacité (2188) exclus
        $this->assertSame(2188 - 216, count($solver->freeBits));
        $this->assertSame(216, $solver->freeBits[0]);
        $this->assertSame([], $solver->freeChars);

        // plus de liberté qu'en mode Full (1972 bits vs 247 chars x 5 = 1235)
        $full = new Solver($spec, 'https://sqr.art/', new SeededRandom(42), UrlMode::Full);
        $this->assertGreaterThan(count($full->freeChars) * count(Solver::BASIS), count($solver->freeBits));
    }
}
