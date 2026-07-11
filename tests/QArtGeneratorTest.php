<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use chillerlan\QRCode\QRCode;
use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Cache\FileMatrixCache;
use SqrArt\QArt\DotShape;
use SqrArt\QArt\FinderShape;
use SqrArt\QArt\QArtGenerator;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\Random\SeededRandom;
use SqrArt\QArt\RenderProfile;
use SqrArt\QArt\Solver;

/**
 * Test de bout en bout : génération complète sur une image synthétique,
 * validation par décodage réel, déterminisme avec graine fixe, cache de
 * matrice génératrice.
 */
final class QArtGeneratorTest extends TestCase
{
    private const PREFIX = 'https://sqr.art/';

    private static string $dir;

    private static string $imagePath;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/qart-tests-'.bin2hex(random_bytes(4));
        mkdir(self::$dir, 0775, true);

        // Portrait synthétique : dégradé radial + formes contrastées
        $size = 500;
        $im = imagecreatetruecolor($size, $size);
        $cx = $size / 2;
        $cy = $size / 2;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / ($size / 2);
                $v = (int) max(0, min(255, 255 * (1 - $d)));
                imagesetpixel($im, $x, $y, ($v << 16) | ((int) ($v * 0.7) << 8) | (255 - $v));
            }
        }
        imagefilledellipse($im, 150, 150, 140, 140, 0x111111);
        imagefilledellipse($im, 360, 340, 180, 180, 0xEEEEEE);
        imagefilledrectangle($im, 60, 350, 200, 460, 0xDD2200);
        self::$imagePath = self::$dir.'/source.png';
        imagepng($im, self::$imagePath);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$dir);
    }

    public function test_generates_validated_scannable_qr_code(): void
    {
        $out = self::$dir.'/qr.png';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 1,
            random: new SeededRandom(42),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
        );
        $t0 = microtime(true);
        $res = $gen->generate(self::$imagePath, $out, RenderProfile::screen());
        $cold = microtime(true) - $t0;

        $this->assertFileExists($out);
        $this->assertStringStartsWith(self::PREFIX, $res->url);
        $this->assertSame(QArtSpec::CAPACITY, strlen($res->url));
        $this->assertSame(QArtSpec::CAPACITY - strlen(self::PREFIX), strlen($res->suffix));
        $this->assertSame(substr($res->suffix, 0, Solver::SERIAL), $res->serial);
        $this->assertMatchesRegularExpression('/^[H-Wh-w]{8}$/', $res->serial);
        $this->assertGreaterThanOrEqual(0, $res->mask);
        $this->assertLessThanOrEqual(7, $res->mask);
        $this->assertSame(1, $res->attempts, 'la première tentative doit décoder');

        // Déterminisme + cache : même graine => même URL, même PNG, plus rapide
        $out2 = self::$dir.'/qr2.png';
        $gen2 = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 1,
            random: new SeededRandom(42),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
        );
        $t0 = microtime(true);
        $res2 = $gen2->generate(self::$imagePath, $out2, RenderProfile::screen());
        $warm = microtime(true) - $t0;

        $this->assertSame($res->url, $res2->url, 'même graine => même URL');
        $this->assertSame(md5_file($out), md5_file($out2), 'sortie déterministe');
        $this->assertLessThan($cold, $warm, 'le cache de matrice doit accélérer la génération');
    }

    public function test_generates_svg_alongside_png_and_svg_decodes(): void
    {
        $outPng = self::$dir.'/qr-svg.png';
        $outSvg = self::$dir.'/qr-svg.svg';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            random: new SeededRandom(11),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
        );
        $profile = RenderProfile::screen()
            ->withDotShape(DotShape::Round)
            ->withFinderShape(FinderShape::Rounded);
        $res = $gen->generate(self::$imagePath, $outPng, $profile, $outSvg);

        $this->assertSame($outSvg, $res->svgPath);
        $this->assertFileExists($outSvg);
        $this->assertNotFalse(@simplexml_load_file($outSvg), 'SVG mal formé');

        // Le PNG (même matrice) est validé par décodage ; si Imagick sait
        // rastériser le SVG, on vérifie aussi le vectoriel de bout en bout.
        if (! extension_loaded('imagick') || \Imagick::queryFormats('SVG') === []) {
            $this->markTestIncomplete('Imagick/SVG indisponible : décodage du SVG rastérisé non vérifié');
        }
        $im = new \Imagick;
        $im->setBackgroundColor('white');
        $im->setResolution(150, 150);
        $im->readImage($outSvg);
        $im->setImageFormat('png');
        $raster = self::$dir.'/qr-svg-raster.png';
        $im->writeImage($raster);
        $decoded = (new QRCode)->readFromFile($raster);
        $this->assertSame($res->url, $decoded->data, 'le SVG rastérisé doit décoder la même URL');
    }

    public function test_print_profile_produces_larger_output(): void
    {
        $out = self::$dir.'/qr-print.png';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            random: new SeededRandom(7),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
        );
        $res = $gen->generate(self::$imagePath, $out, RenderProfile::print());
        [$w] = getimagesize($out);
        // print: scale 4 => (399 + 2*4*7) * 4
        $this->assertSame((399 + 56) * 4, $w);
        $this->assertSame(1, $res->attempts);
    }
}
