<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\Random\SeededRandom;
use SqrArt\QArt\Solver;

final class SolverTest extends TestCase
{
    public function testCosetAlphabetIs32UrlSafeLetters(): void
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

    public function testSerialUsesOnlyCosetAlphabetAndIsSeedable(): void
    {
        $spec = new QArtSpec();
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

    public function testBaseUrlHasFullCapacity(): void
    {
        $spec = new QArtSpec();
        $solver = new Solver($spec, 'https://sqr.art/', new SeededRandom(1));
        $this->assertSame(QArtSpec::CAPACITY, strlen($solver->baseUrl));
        $this->assertStringStartsWith('https://sqr.art/', $solver->baseUrl);
    }

    public function testRejectsOversizedPrefix(): void
    {
        $this->expectException(QArtException::class);
        new Solver(new QArtSpec(), str_repeat('a', QArtSpec::CAPACITY), new SeededRandom(1));
    }

    public function testRejectsNonAsciiPrefix(): void
    {
        $this->expectException(QArtException::class);
        new Solver(new QArtSpec(), "https://sqr.art/é", new SeededRandom(1));
    }
}
