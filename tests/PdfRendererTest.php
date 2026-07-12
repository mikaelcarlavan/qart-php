<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\DotShape;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\FinderShape;
use SqrArt\QArt\ImagePipeline;
use SqrArt\QArt\PdfRenderer;
use SqrArt\QArt\QArtSpec;
use SqrArt\QArt\RenderMode;
use SqrArt\QArt\RenderProfile;

final class PdfRendererTest extends TestCase
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

        $n = self::$spec->n;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                self::$matrix[$r][$c] = self::$spec->fmap[$r][$c]
                    ? (($r + $c) % 2)
                    : (($r ^ $c) & 1);
            }
        }
    }

    /** Flux de contenu décompressé du PDF. */
    private static function stream(string $pdf): string
    {
        $start = strpos($pdf, "stream\n");
        $end = strrpos($pdf, "\nendstream");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $raw = substr($pdf, $start + 7, $end - $start - 7);
        $inflated = @gzuncompress($raw);
        self::assertNotFalse($inflated, 'flux FlateDecode invalide');

        return $inflated;
    }

    public function test_produces_a_structurally_valid_pdf_at_the_requested_size(): void
    {
        $pdf = PdfRenderer::colorHalftone(self::$img, self::$spec, self::$matrix, RenderProfile::screen());

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('xref', $pdf);
        $this->assertStringContainsString('/Root 1 0 R', $pdf);
        // 100 mm = 283.465 pt
        $this->assertStringContainsString('/MediaBox [0 0 283.465 283.465]', $pdf);
        // les offsets xref pointent bien sur les objets
        preg_match_all('/^(\d{10}) 00000 n /m', $pdf, $m);
        $this->assertCount(4, $m[1]);
        foreach ($m[1] as $i => $offset) {
            $this->assertSame(($i + 1).' 0 obj', substr($pdf, (int) $offset, strlen(($i + 1).' 0 obj')));
        }

        $ops = self::stream($pdf);
        $this->assertStringContainsString(' re f', $ops);
        $this->assertStringContainsString(' rg', $ops);
        // page carrée : la matrice de passage écrase y (repère image)
        $this->assertStringContainsString('0 283.465 cm', $ops);
    }

    public function test_respects_custom_physical_size(): void
    {
        $pdf = PdfRenderer::colorHalftone(self::$img, self::$spec, self::$matrix, RenderProfile::screen(), 50.0);
        // 50 mm = 141.732 pt
        $this->assertStringContainsString('/MediaBox [0 0 141.732 141.732]', $pdf);
    }

    public function test_round_dots_and_rounded_finders_emit_bezier_curves(): void
    {
        $square = PdfRenderer::colorHalftone(self::$img, self::$spec, self::$matrix, RenderProfile::screen());
        $round = PdfRenderer::colorHalftone(
            self::$img, self::$spec, self::$matrix,
            RenderProfile::screen()->withDotShape(DotShape::Round)->withFinderShape(FinderShape::Rounded)
        );

        $this->assertStringNotContainsString(" c\n", self::stream($square));
        $this->assertGreaterThan(2768 * 4, substr_count(self::stream($round), " c\n"), 'courbes de Bézier attendues');
    }

    public function test_pixel_art_mode_is_compact_and_full_module(): void
    {
        $profile = RenderProfile::screen()->withMode(RenderMode::Module);
        $pdf = PdfRenderer::pixelArt(self::$img, self::$spec, self::$matrix, $profile);
        $halftone = PdfRenderer::colorHalftone(self::$img, self::$spec, self::$matrix, RenderProfile::screen());

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $ops = self::stream($pdf);
        $this->assertStringContainsString(' re f', $ops);
        // plein module : bien moins d'opérations que la texture halftone
        $this->assertLessThan(strlen($halftone) / 4, strlen($pdf));
    }

    public function test_rejects_degenerate_size(): void
    {
        $this->expectException(QArtException::class);
        PdfRenderer::colorHalftone(self::$img, self::$spec, self::$matrix, RenderProfile::screen(), 0.2);
    }
}
