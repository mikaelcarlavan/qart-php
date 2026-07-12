<?php

declare(strict_types=1);

namespace SqrArt\QArt\Native;

/**
 * Accélération native (Rust, via FFI) de l'élimination gaussienne GF(2) —
 * le coût dominant d'une génération, prohibitif en PHP pur au-delà de v15.
 *
 * Entièrement optionnelle : si l'extension FFI n'est pas active
 * (ffi.enable=1 requis hors preload) ou si la librairie n'est pas bâtie
 * (`cargo build --release` dans native/), le solveur retombe sur
 * l'implémentation PHP pure. Le résultat est identique octet pour octet :
 * même sélection de pivots, mêmes colonnes éliminées.
 *
 * Chemin de la librairie : $QART_GF2_LIB, sinon native/target/release/.
 */
final class Gf2
{
    private const HEADER = <<<'C'
    size_t qart_eliminate(
        uint8_t *cols, size_t ncols, size_t col_bytes,
        uint8_t *comp, size_t comp_bytes,
        const uint32_t *order, size_t order_len,
        uint32_t *pivot_pos, uint32_t *pivot_col);
    C;

    private static ?\FFI $ffi = null;

    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        if (! extension_loaded('ffi')) {
            return self::$available = false;
        }
        $lib = self::libraryPath();
        if ($lib === null) {
            return self::$available = false;
        }
        try {
            self::$ffi = \FFI::cdef(self::HEADER, $lib);

            return self::$available = true;
        } catch (\Throwable) {
            // ffi.enable=preload, librairie incompatible… : repli PHP
            return self::$available = false;
        }
    }

    /** Force une nouvelle détection (tests). */
    public static function reset(): void
    {
        self::$ffi = null;
        self::$available = null;
    }

    public static function libraryPath(): ?string
    {
        $candidates = [];
        $env = getenv('QART_GF2_LIB');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        $ext = match (PHP_OS_FAMILY) {
            'Darwin' => 'dylib',
            'Windows' => 'dll',
            default => 'so',
        };
        $prefix = PHP_OS_FAMILY === 'Windows' ? '' : 'lib';
        $candidates[] = \dirname(__DIR__, 2)."/native/target/release/{$prefix}qart_gf2.{$ext}";

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Élimination en place : $cols et $comp ressortent éliminés, comme
     * après Solver::eliminate.
     *
     * @param  string[]  $cols  colonnes (bitsets de longueur identique)
     * @param  string[]  $comp  compositions (bitsets de longueur identique)
     * @param  int[]  $order  positions de modules triées par importance
     * @return array<array{0:int,1:int}> pivots [(position, colonne)]
     */
    public static function eliminate(array &$cols, array &$comp, array $order): array
    {
        $ncols = count($cols);
        if ($ncols === 0) {
            return [];
        }
        $colBytes = strlen($cols[0]);
        $compBytes = strlen($comp[0]);
        $ffi = self::$ffi;

        $cCols = $ffi->new('uint8_t['.($ncols * $colBytes).']');
        $cComp = $ffi->new('uint8_t['.($ncols * $compBytes).']');
        \FFI::memcpy($cCols, implode('', $cols), $ncols * $colBytes);
        \FFI::memcpy($cComp, implode('', $comp), $ncols * $compBytes);

        $cOrder = $ffi->new('uint32_t['.max(1, count($order)).']');
        foreach (array_values($order) as $i => $pos) {
            $cOrder[$i] = $pos;
        }
        $cPos = $ffi->new("uint32_t[$ncols]");
        $cCol = $ffi->new("uint32_t[$ncols]");

        $npiv = $ffi->qart_eliminate(
            $cCols, $ncols, $colBytes,
            $cComp, $compBytes,
            $cOrder, count($order),
            $cPos, $cCol,
        );

        $cols = str_split(\FFI::string($cCols, $ncols * $colBytes), $colBytes);
        $comp = str_split(\FFI::string($cComp, $ncols * $compBytes), $compBytes);

        $pivots = [];
        for ($i = 0; $i < $npiv; $i++) {
            $pivots[] = [$cPos[$i], $cCol[$i]];
        }

        return $pivots;
    }
}
