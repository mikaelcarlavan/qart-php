<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
use SqrArt\QArt\Exception\ImageException;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\ImportanceMap;
use SqrArt\QArt\QArtSpec;

final class ImportanceMapTest extends TestCase
{
    public function test_projects_fractional_zone_onto_module_grid(): void
    {
        $spec = new QArtSpec(10);   // 57 modules
        $mask = (new ImportanceMap)->protect(0.5, 0.5, 0.1, 0.1)->moduleMask($spec);

        // 0.5 * 57 = 28.5 -> modules 28 à ceil(0.6*57)-1 = 34
        $this->assertTrue($mask[28][28]);
        $this->assertTrue($mask[34][34]);
        $this->assertFalse($mask[27][27]);
        $this->assertFalse($mask[35][35]);
    }

    public function test_zone_is_clamped_to_image_bounds(): void
    {
        $spec = new QArtSpec(10);
        $mask = (new ImportanceMap)->protect(0.9, 0.9, 0.5, 0.5)->moduleMask($spec);
        $this->assertTrue($mask[56][56]);
        $this->assertFalse($mask[0][0]);
    }

    public function test_supports_multiple_zones(): void
    {
        $spec = new QArtSpec(10);
        $mask = (new ImportanceMap)
            ->protect(0.0, 0.0, 0.1, 0.1)
            ->protect(0.8, 0.8, 0.1, 0.1)
            ->moduleMask($spec);
        $this->assertTrue($mask[0][0]);
        $this->assertTrue($mask[48][48]);
        $this->assertFalse($mask[28][28]);
    }

    public function test_painted_mask_maps_to_module_weights(): void
    {
        // masque 200x200 : disque blanc en haut à gauche sur fond noir
        $mask = imagecreatetruecolor(200, 200);
        imagefilledrectangle($mask, 0, 0, 199, 199, 0x000000);
        imagefilledellipse($mask, 50, 50, 80, 80, 0xFFFFFF);
        ob_start();
        imagepng($mask);
        $data = ob_get_clean();

        $map = (new ImportanceMap)->paint($data);
        $this->assertTrue($map->hasPaint());
        $this->assertFalse($map->hasZones());

        $weights = $map->moduleWeights(new QArtSpec(10));
        // centre du disque (50/200 = 0.25 -> module 14) : poids fort
        $this->assertGreaterThan(0.8, $weights[14][14]);
        // coin opposé : poids nul
        $this->assertLessThan(0.05, $weights[50][50]);
    }

    public function test_transparent_mask_regions_weigh_nothing(): void
    {
        $mask = imagecreatetruecolor(100, 100);
        imagesavealpha($mask, true);
        imagealphablending($mask, false);
        imagefilledrectangle($mask, 0, 0, 99, 99, imagecolorallocatealpha($mask, 0, 0, 0, 127));
        imagealphablending($mask, true);
        imagefilledrectangle($mask, 0, 0, 49, 99, 0xFFFFFF);   // gauche peinte
        ob_start();
        imagepng($mask);
        $data = ob_get_clean();

        $weights = (new ImportanceMap)->paint($data)->moduleWeights(new QArtSpec(10));
        $this->assertGreaterThan(0.8, $weights[28][10]);
        $this->assertLessThan(0.05, $weights[28][45]);
    }

    public function test_rejects_unreadable_paint_mask(): void
    {
        $this->expectException(ImageException::class);
        (new ImportanceMap)->paint('pas une image');
    }

    public function test_rejects_out_of_range_zone(): void
    {
        $this->expectException(QArtException::class);
        (new ImportanceMap)->protect(1.2, 0.0, 0.1, 0.1);
    }

    public function test_rejects_empty_zone(): void
    {
        $this->expectException(QArtException::class);
        (new ImportanceMap)->protect(0.2, 0.2, 0.0, 0.1);
    }
}
