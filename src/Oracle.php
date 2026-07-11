<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Encodeur QR conforme (chillerlan) utilisé comme oracle : rend une URL en
 * matrice de bits packée pour une version/ECC/masque figés.
 */
final class Oracle
{
    /** Rend l'URL en matrice de bits packée (n*n bits, MSB d'abord). */
    public static function render(string $url, int $mask, int $version = QArtSpec::DEFAULT_VERSION): string
    {
        $qr = new QRCode(new QROptions([
            'version' => $version,
            'eccLevel' => EccLevel::L,
            'maskPattern' => $mask,
            'addQuietzone' => false,
        ]));
        $qr->addByteSegment($url);
        $m = $qr->getQRMatrix()->matrix(true);
        $n = 17 + 4 * $version;
        $packed = str_repeat("\0", intdiv($n * $n + 7, 8));
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$r][$c]) {
                    $p = $r * $n + $c;
                    $packed[$p >> 3] = chr(ord($packed[$p >> 3]) | (0x80 >> ($p & 7)));
                }
            }
        }

        return $packed;
    }
}
