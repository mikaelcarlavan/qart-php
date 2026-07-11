<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/** Accès bit à bit dans des chaînes binaires packées (MSB d'abord). */
final class Bits
{
    public static function get(string $s, int $p): int
    {
        return (ord($s[$p >> 3]) >> (7 - ($p & 7))) & 1;
    }

    public static function set(string &$s, int $p, int $v): void
    {
        $mask = 0x80 >> ($p & 7);
        $byte = ord($s[$p >> 3]);
        $s[$p >> 3] = chr($v ? ($byte | $mask) : ($byte & ~$mask));
    }
}
