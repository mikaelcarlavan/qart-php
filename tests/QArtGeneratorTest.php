<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use chillerlan\QRCode\QRCode;
use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Cache\FileMatrixCache;
use SqrArt\QArt\Dithering;
use SqrArt\QArt\DotShape;
use SqrArt\QArt\Ecc;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\FinderShape;
use SqrArt\QArt\ImagePipeline;
use SqrArt\QArt\ImportanceMap;
use SqrArt\QArt\QArtGenerator;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\Random\SeededRandom;
use SqrArt\QArt\RenderMode;
use SqrArt\QArt\RenderProfile;
use SqrArt\QArt\Solver;
use SqrArt\QArt\UrlMode;

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
        $this->assertSame((new QArtSpec)->capacity, strlen($res->url));
        $this->assertSame((new QArtSpec)->capacity - strlen(self::PREFIX), strlen($res->suffix));
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

    /**
     * LE test du mode padding bits : l'URL décodée doit être courte
     * (préfixe + série) alors que le padding porte l'image. Prouve que le
     * décodeur (port ZXing) ignore le contenu du padding non standard.
     */
    public function test_short_mode_decodes_to_clean_url(): void
    {
        $out = self::$dir.'/qr-short.png';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 1,
            random: new SeededRandom(42),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
            urlMode: UrlMode::Short,
        );
        $res = $gen->generate(self::$imagePath, $out);

        // URL courte : préfixe + série de 8, rien d'autre
        $this->assertSame(strlen(self::PREFIX) + Solver::SERIAL, strlen($res->url));
        $this->assertSame($res->serial, $res->suffix, 'en mode Short le suffixe est la série');
        $this->assertMatchesRegularExpression('/^[H-Wh-w]{8}$/', $res->suffix);
        $this->assertSame(1, $res->attempts, 'le PNG doit décoder du premier coup');

        // décodage indépendant : l'URL lue est exactement la courte
        $decoded = (new QRCode)->readFromFile($out);
        $this->assertSame(self::PREFIX.$res->serial, $decoded->data);
    }

    public function test_short_mode_improves_fidelity_over_full(): void
    {
        // même image, même graine : le mode Short (1972 bits libres) doit
        // faire au moins aussi bien que le mode Full (1235 bits)
        $score = function (UrlMode $mode): float {
            $out = self::$dir.'/qr-fid-'.$mode->value.'.png';
            $gen = new QArtGenerator(
                prefix: self::PREFIX,
                errorBudgetPerBlock: 0,
                random: new SeededRandom(7),
                matrixCache: new FileMatrixCache(self::$dir.'/cache'),
                urlMode: $mode,
            );
            $gen->generate(self::$imagePath, $out);

            // fidélité mesurée sur les points de données du PNG produit
            $img = ImagePipeline::fromFile(self::$imagePath);
            $png = imagecreatefrompng($out);
            $spec = new QArtSpec;
            $s = ImagePipeline::S;
            $scale = 3;
            $border = 4 * $s;
            $good = 0.0;
            for ($r = 0; $r < $spec->n; $r++) {
                for ($c = 0; $c < $spec->n; $c++) {
                    if ($spec->fmap[$r][$c]) {
                        continue;
                    }
                    $px = imagecolorat($png, ($c * $s + 3 + $border) * $scale + 1, ($r * $s + 3 + $border) * $scale + 1);
                    $lum = (0.299 * (($px >> 16) & 0xFF) + 0.587 * (($px >> 8) & 0xFF) + 0.114 * ($px & 0xFF)) / 255;
                    $bit = $lum < 0.5 ? 1 : 0;
                    if ($bit === $img->target[$r][$c]) {
                        $good += $img->conf[$r][$c];
                    }
                }
            }

            return $good;
        };

        $this->assertGreaterThanOrEqual($score(UrlMode::Full), $score(UrlMode::Short));
    }

    /**
     * Zone protégée : tous ses modules de données doivent être fidèles à
     * l'image dans la matrice finale (pivots prioritaires + budget d'erreur),
     * et le QR doit toujours décoder.
     */
    public function test_protected_zone_is_fully_faithful(): void
    {
        $out = self::$dir.'/qr-protected.png';
        $importance = (new ImportanceMap)->protect(0.35, 0.35, 0.3, 0.3);
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 2,
            random: new SeededRandom(42),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
            urlMode: UrlMode::Short,
        );
        $res = $gen->generate(self::$imagePath, $out, importance: $importance);

        $this->assertSame(0, $res->protectedMismatches, 'zone protégée non garantie');
        $this->assertSame(1, $res->attempts);
        $this->assertStringNotContainsString('zone protégée', implode(' ', $res->warnings));

        // sans carte : protectedMismatches doit rester null
        $res2 = $gen->generate(self::$imagePath, self::$dir.'/qr-noprot.png');
        $this->assertNull($res2->protectedMismatches);
    }

    public function test_oversized_protected_zone_reports_mismatches(): void
    {
        // zone quasi totale : impossible à garantir entièrement, le résultat
        // doit le dire au lieu de le cacher
        $out = self::$dir.'/qr-overprotected.png';
        $importance = (new ImportanceMap)->protect(0.02, 0.02, 0.96, 0.96);
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 1,
            random: new SeededRandom(9),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
        );
        $res = $gen->generate(self::$imagePath, $out, importance: $importance);

        $this->assertNotNull($res->protectedMismatches);
        $this->assertGreaterThan(0, $res->protectedMismatches);
        $this->assertStringContainsString('zone protégée', implode(' ', $res->warnings));
    }

    /**
     * ECC H : capacité réduite mais budget d'erreur quadruplé. La génération
     * complète doit décoder, avec un budget bien au-delà du plafond de L.
     */
    public function test_generates_with_high_ecc_and_larger_error_budget(): void
    {
        $out = self::$dir.'/qr-ecc-h.png';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 6,   // impossible en L (max 7 pour 18 ecc/bloc, mais 4 était le cap historique)
            random: new SeededRandom(42),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
            urlMode: UrlMode::Short,
            ecc: Ecc::H,
        );
        $res = $gen->generate(self::$imagePath, $out);

        $this->assertSame(1, $res->attempts, 'le QR ECC H doit décoder du premier coup');
        $decoded = (new QRCode)->readFromFile($out);
        $this->assertSame(self::PREFIX.$res->serial, $decoded->data);
    }

    public function test_rejects_error_budget_beyond_rs_capacity(): void
    {
        // v10-L : 18 codewords ECC/bloc => max autorisé 7
        $gen = new QArtGenerator(prefix: self::PREFIX, errorBudgetPerBlock: 8);
        $this->expectException(QArtException::class);
        $this->expectExceptionMessageMatches('/trop élevé/');
        $gen->generate(self::$imagePath, self::$dir.'/qr-overbudget.png');
    }

    /**
     * Pixel art plein module : le QR entier est l'image dithérée. ECC H +
     * gros budget d'erreur (chaque codeword sacrifié force 8 pixels).
     */
    public function test_generates_pixel_art_with_high_ecc(): void
    {
        $outPng = self::$dir.'/qr-pixel.png';
        $outSvg = self::$dir.'/qr-pixel.svg';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 8,
            random: new SeededRandom(11),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
            urlMode: UrlMode::Short,
            ecc: Ecc::H,
        );
        $profile = RenderProfile::screen()->withMode(RenderMode::Module);
        $res = $gen->generate(self::$imagePath, $outPng, $profile, $outSvg);

        $decoded = (new QRCode)->readFromFile($outPng);
        $this->assertSame(self::PREFIX.$res->serial, $decoded->data);

        $svg = file_get_contents($outSvg);
        $this->assertNotFalse(@simplexml_load_string($svg), 'SVG mal formé');
        // plein module : pas de texture sous-pixel ni de points
        $this->assertStringNotContainsString('height="1"', $svg);
        $this->assertStringNotContainsString('<circle', $svg);
    }

    public function test_generates_pixel_art_with_ordered_dithering(): void
    {
        $out = self::$dir.'/qr-pixel-ordered.png';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 8,
            random: new SeededRandom(13),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
            urlMode: UrlMode::Short,
            ecc: Ecc::H,
        );
        $profile = RenderProfile::screen()
            ->withMode(RenderMode::Module)
            ->withDithering(Dithering::Ordered);
        $res = $gen->generate(self::$imagePath, $out, $profile);

        $decoded = (new QRCode)->readFromFile($out);
        $this->assertSame(self::PREFIX.$res->serial, $decoded->data);
    }

    public function test_generates_pdf_alongside_png_and_pdf_decodes(): void
    {
        $outPng = self::$dir.'/qr-pdf.png';
        $outPdf = self::$dir.'/qr-pdf.pdf';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            errorBudgetPerBlock: 1,
            random: new SeededRandom(21),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
        );
        $res = $gen->generate(self::$imagePath, $outPng, null, null, null, null, $outPdf);

        $this->assertSame($outPdf, $res->pdfPath);
        $this->assertFileExists($outPdf);
        $pdf = file_get_contents($outPdf);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);

        // rastérisation (Imagick délègue à Ghostscript) puis décodage réel
        if (! extension_loaded('imagick') || \Imagick::queryFormats('PDF') === []) {
            $this->markTestIncomplete('Imagick/PDF indisponible : décodage du PDF rastérisé non vérifié');
        }
        $im = new \Imagick;
        $im->setBackgroundColor('white');
        $im->setResolution(300, 300);
        try {
            $im->readImage($outPdf);
        } catch (\ImagickException) {
            $this->markTestIncomplete('Ghostscript indisponible : décodage du PDF rastérisé non vérifié');
        }
        // aplatir l'alpha : le lecteur GD interprète mal la transparence
        $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $im->setImageFormat('png');
        $raster = self::$dir.'/qr-pdf-raster.png';
        $im->writeImage($raster);
        $decoded = (new QRCode)->readFromFile($raster);
        $this->assertSame($res->url, $decoded->data);
    }

    public function test_generates_at_version_5(): void
    {
        $out = self::$dir.'/qr-v5.png';
        $gen = new QArtGenerator(
            prefix: self::PREFIX,
            random: new SeededRandom(3),
            matrixCache: new FileMatrixCache(self::$dir.'/cache'),
            version: 5,
        );
        $res = $gen->generate(self::$imagePath, $out);

        $spec = new QArtSpec(5);
        $this->assertSame($spec->capacity, strlen($res->url));
        $this->assertSame(1, $res->attempts, 'le décodage v5 doit réussir du premier coup');
        // 37 modules + 2x4 de bord, 7 sous-pixels, échelle 3
        [$w] = getimagesize($out);
        $this->assertSame((37 + 8) * 7 * 3, $w);
    }

    public function test_version_too_small_for_prefix_is_rejected(): void
    {
        $gen = new QArtGenerator(prefix: self::PREFIX, version: 1);
        $this->expectException(QArtException::class);
        $gen->generate(self::$imagePath, self::$dir.'/qr-v1.png');
    }

    public function test_suggest_version_scales_with_detail(): void
    {
        // aplat simple -> version basse
        $flat = self::$dir.'/flat.png';
        $im = imagecreatetruecolor(300, 300);
        imagefilledrectangle($im, 0, 0, 299, 299, 0xE0E0E0);
        imagefilledellipse($im, 150, 150, 200, 200, 0x203040);
        imagepng($im, $flat);

        // structure dense contrastée (survit au sous-échantillonnage) -> version haute
        $busy = self::$dir.'/busy.png';
        $im2 = imagecreatetruecolor(300, 300);
        $seed = 12345;
        for ($by = 0; $by < 300; $by += 12) {
            for ($bx = 0; $bx < 300; $bx += 12) {
                $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
                $v = $seed & 0xFF;
                imagefilledrectangle($im2, $bx, $by, $bx + 11, $by + 11, ($v << 16) | ($v << 8) | $v);
            }
        }
        imagepng($im2, $busy);

        $low = QArtGenerator::suggestVersion($flat);
        $high = QArtGenerator::suggestVersion($busy);
        $this->assertLessThan($high, $low);
        $this->assertContains($low, [5, 10, 15]);
        $this->assertContains($high, [5, 10, 15]);
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
