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
    /** Rend l'URL en matrice de bits packée (chaîne de 407 octets, 3249 bits). */
    public static function render(string $url, int $mask): string
    {
        $qr = new QRCode(new QROptions([
            'version'      => 10,
            'eccLevel'     => EccLevel::L,
            'maskPattern'  => $mask,
            'addQuietzone' => false,
        ]));
        $qr->addByteSegment($url);
        $m = $qr->getQRMatrix()->matrix(true);
        $n = QArtSpec::N;
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
