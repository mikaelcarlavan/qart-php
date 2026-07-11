<?php

declare(strict_types=1);

namespace SqrArt\QArt\Tests;

use PHPUnit\Framework\TestCase;
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
