<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Exception\ImageException;
use SqrArt\QArt\ImagePipeline;

final class ImagePipelineTest extends TestCase
{
    /** Image de test contrastée : dégradé + damier. */
    private static function makeTestImage(int $size): \GdImage
    {
        $im = imagecreatetruecolor($size, $size);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $v = (int) (255 * $x / $size);
                $checker = (intdiv($x, 32) + intdiv($y, 32)) % 2 === 0;
                $col = $checker
                    ? ($v << 16) | (64 << 8) | (255 - $v)
                    : ((255 - $v) << 16) | (200 << 8) | $v;
                imagesetpixel($im, $x, $y, $col);
            }
        }

        return $im;
    }

    public function test_computes_target_and_confidence_grids(): void
    {
        $img = new ImagePipeline(self::makeTestImage(500));
        $n = 57;
        $this->assertCount($n, $img->target);
        $this->assertCount($n, $img->conf);
        foreach ($img->conf as $row) {
            foreach ($row as $v) {
                $this->assertGreaterThanOrEqual(0.0, $v);
                $this->assertLessThanOrEqual(1.0, $v);
            }
        }
        $this->assertSame([], $img->warnings);
    }

    public function test_accepts_jpeg_and_webp_and_png(): void
    {
        $src = self::makeTestImage(450);
        foreach (['imagejpeg', 'imagewebp', 'imagepng'] as $fn) {
            ob_start();
            $fn($src);
            $data = ob_get_clean();
            $img = ImagePipeline::fromString($data);
            $this->assertCount(57, $img->target, "échec pour $fn");
        }
    }

    public function test_rejects_garbage_data(): void
    {
        $this->expectException(ImageException::class);
        ImagePipeline::fromString('ceci n\'est pas une image');
    }

    public function test_rejects_flat_image(): void
    {
        $im = imagecreatetruecolor(500, 500);
        imagefilledrectangle($im, 0, 0, 499, 499, 0x808080);
        $this->expectException(ImageException::class);
        new ImagePipeline($im);
    }

    public function test_warns_on_low_contrast(): void
    {
        $im = imagecreatetruecolor(500, 500);
        for ($y = 0; $y < 500; $y++) {
            for ($x = 0; $x < 500; $x++) {
                $v = 120 + (int) (16 * $x / 500);   // dégradé très plat mais non nul
                imagesetpixel($im, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        $img = new ImagePipeline($im);
        $this->assertNotEmpty($img->warnings);
        $this->assertStringContainsString('contraste', implode(' ', $img->warnings));
    }

    public function test_warns_on_small_image_and_upscales(): void
    {
        $img = new ImagePipeline(self::makeTestImage(120));
        $this->assertNotEmpty($img->warnings);
        $this->assertStringContainsString('agrandie', implode(' ', $img->warnings));
        $this->assertCount(57, $img->target);
    }

    public function test_flattens_alpha_on_white(): void
    {
        $im = imagecreatetruecolor(500, 500);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 499, 499, $transparent);
        imagealphablending($im, true);
        // moitié gauche noire opaque, moitié droite transparente (=> blanche)
        imagefilledrectangle($im, 0, 0, 249, 499, 0x000000);

        $img = new ImagePipeline($im);
        $this->assertSame(1, $img->target[28][5], 'gauche = noir');
        $this->assertSame(0, $img->target[28][51], 'droite transparente = blanc');
    }
}
