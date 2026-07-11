<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\DotShape;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\FinderShape;
use SqrArt\QArt\ImagePipeline;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\RenderProfile;
use SqrArt\QArt\SvgRenderer;

final class SvgRendererTest extends TestCase
{
    private static QArtSpec $spec;

    private static ImagePipeline $img;

    /** @var int[][] */
    private static array $matrix;

    public static function setUpBeforeClass(): void
    {
        self::$spec = new QArtSpec;

        $size = 450;
        $im = imagecreatetruecolor($size, $size);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $v = (int) (255 * $x / $size);
                imagesetpixel($im, $x, $y, ($v << 16) | (128 << 8) | (255 - $v));
            }
        }
        imagefilledellipse($im, 225, 225, 250, 250, 0x101010);
        self::$img = new ImagePipeline($im);

        // matrice arbitraire mais déterministe : damier sur les modules de données
        $n = self::$spec->n;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                self::$matrix[$r][$c] = self::$spec->fmap[$r][$c]
                    ? (($r + $c) % 2)
                    : (($r ^ $c) & 1);
            }
        }
    }

    public function test_produces_well_formed_svg_with_expected_geometry(): void
    {
        $svg = SvgRenderer::colorHalftone(self::$img, self::$spec, self::$matrix, RenderProfile::screen());

        $xml = @simplexml_load_string($svg);
        $this->assertNotFalse($xml, 'SVG mal formé');

        // viewBox : 57 modules * 7 sous-pixels + 2 * 4 modules de bord
        $expected = (57 + 8) * 7;
        $this->assertSame("0 0 $expected $expected", (string) $xml['viewBox']);

        // un point par module de données (rects carrés par défaut)
        $this->assertSame(2768, substr_count($svg, 'width="3" height="3"'));
    }

    public function test_run_merging_keeps_file_reasonable(): void
    {
        $svg = SvgRenderer::colorHalftone(self::$img, self::$spec, self::$matrix, RenderProfile::screen());

        $rects = substr_count($svg, '<rect');
        // 159k sous-pixels de texture : la fusion par plages doit compresser
        $this->assertLessThan(120_000, $rects, "fusion par plages inefficace: $rects rects");
        $this->assertGreaterThan(1000, $rects);
    }

    public function test_dot_shapes(): void
    {
        $round = SvgRenderer::colorHalftone(
            self::$img, self::$spec, self::$matrix,
            RenderProfile::screen()->withDotShape(DotShape::Round)
        );
        $this->assertSame(2768, substr_count($round, '<circle'));

        $diamond = SvgRenderer::colorHalftone(
            self::$img, self::$spec, self::$matrix,
            RenderProfile::screen()->withDotShape(DotShape::Diamond)
        );
        $this->assertSame(2768, substr_count($diamond, '<path'));
    }

    public function test_rounded_finders_and_brand_color(): void
    {
        $svg = SvgRenderer::colorHalftone(
            self::$img, self::$spec, self::$matrix,
            RenderProfile::screen()
                ->withFinderShape(FinderShape::Rounded)
                ->withFinderColor('#1a2b4c')
        );

        // 3 finders x 3 rounded rects, coeur 3x3 avec rx = 7
        $this->assertSame(3, substr_count($svg, 'width="21" height="21" rx="7"'));
        $this->assertStringContainsString('#1a2b4c', $svg);
    }

    public function test_rejects_too_light_finder_color(): void
    {
        $this->expectException(QArtException::class);
        RenderProfile::screen()->withFinderColor('#ffcc00');
    }

    public function test_rejects_malformed_finder_color(): void
    {
        $this->expectException(QArtException::class);
        RenderProfile::screen()->withFinderColor('bleu');
    }
}
