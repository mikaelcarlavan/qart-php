<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Dithering;
use SqrArt\QArt\Exception\ImageException;
use SqrArt\QArt\Fit;
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

    public function test_dithering_algorithms_produce_distinct_textures(): void
    {
        $textures = [];
        $targets = [];
        foreach (Dithering::cases() as $d) {
            $img = new ImagePipeline(self::makeTestImage(500), 57, null, false, $d);
            $textures[$d->value] = $img->dith;
            $pix = new ImagePipeline(self::makeTestImage(500), 57, null, true, $d);
            $targets[$d->value] = $pix->target;
            foreach ($pix->target as $row) {
                foreach ($row as $v) {
                    $this->assertContains($v, [0, 1]);
                }
            }
        }
        // deux à deux distinctes sur le dégradé, texture comme cible pixel art
        $names = array_keys($textures);
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                $this->assertNotSame($textures[$a], $textures[$b], "textures $a et $b identiques");
                $this->assertNotSame($targets[$a], $targets[$b], "cibles $a et $b identiques");
            }
        }
    }

    public function test_module_dither_targets_for_pixel_art(): void
    {
        $halftone = new ImagePipeline(self::makeTestImage(500));
        $pixel = new ImagePipeline(self::makeTestImage(500), 57, null, true);

        $this->assertCount(57, $pixel->target);
        $this->assertCount(57, $pixel->moduleRgb);
        $diff = 0;
        foreach ($pixel->target as $r => $row) {
            $this->assertCount(57, $row);
            foreach ($row as $c => $v) {
                $this->assertContains($v, [0, 1]);
                $this->assertGreaterThanOrEqual(0.0, $pixel->conf[$r][$c]);
                $this->assertLessThanOrEqual(1.0, $pixel->conf[$r][$c]);
                foreach ($pixel->moduleRgb[$r][$c] as $ch) {
                    $this->assertGreaterThanOrEqual(0.0, $ch);
                    $this->assertLessThanOrEqual(1.0, $ch);
                }
                if ($v !== $halftone->target[$r][$c]) {
                    $diff++;
                }
            }
        }
        // le dithering diffuse l'erreur dans les tons moyens : la cible
        // pixel art doit différer du simple seuillage du coeur 3x3
        $this->assertGreaterThan(0, $diff, 'dither module identique au seuillage');
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

    public function test_contain_keeps_the_whole_image_on_white(): void
    {
        // image large 2:1, moitié gauche sombre, moitié droite rouge
        $im = imagecreatetruecolor(800, 400);
        imagefilledrectangle($im, 0, 0, 399, 399, 0x101010);
        imagefilledrectangle($im, 400, 0, 799, 399, 0xC03020);

        $cover = new ImagePipeline($im, 57);
        $contain = new ImagePipeline($im, 57, null, false, Dithering::Atkinson, Fit::Contain);

        // en Contain : bandes blanches en haut et en bas, contenu au centre
        $this->assertGreaterThan(0.95, $contain->rgb[2][199][0], 'haut non blanc');
        $this->assertGreaterThan(0.95, $contain->rgb[396][199][0], 'bas non blanc');
        // les deux moitiés sont présentes (le cover, lui, aurait tout le rouge à droite du centre)
        $this->assertLessThan(0.3, $contain->gray[199][80], 'moitié sombre absente');
        $this->assertGreaterThan(0.3, $contain->rgb[199][330][0], 'moitié rouge absente');
        // et le cover recadre bien (pas de blanc en haut)
        $this->assertLessThan(0.95, $cover->rgb[2][199][0]);
    }

    public function test_crop_selects_the_requested_region(): void
    {
        // moitié gauche noire, moitié droite blanche
        $im = imagecreatetruecolor(800, 800);
        imagefilledrectangle($im, 0, 0, 399, 799, 0x000000);
        imagefilledrectangle($im, 400, 0, 799, 799, 0xFFFFFF);

        // chaque fenêtre chevauche légèrement la frontière (contraste requis)
        $left = new ImagePipeline($im, 57, ['x' => 0.08, 'y' => 0.25, 'size' => 0.45]);
        $right = new ImagePipeline($im, 57, ['x' => 0.47, 'y' => 0.25, 'size' => 0.45]);

        $this->assertSame(1, $left->target[28][28], 'recadrage gauche = noir');
        $this->assertSame(0, $right->target[28][28], 'recadrage droit = blanc');
    }

    public function test_crop_is_clamped_to_image_bounds(): void
    {
        $img = new ImagePipeline(self::makeTestImage(500), 57, ['x' => 0.9, 'y' => 0.9, 'size' => 0.5]);
        $this->assertCount(57, $img->target);   // pas d'exception, fenêtre ramenée dans l'image
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
