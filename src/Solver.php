<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Cache\MatrixCache;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\Random\RandomSource;

/**
 * Solveur GF(2) : matrice génératrice empirique (une colonne par bit libre
 * de l'URL) + élimination gaussienne avec pivots par importance visuelle.
 *
 * La matrice génératrice ne dépend que de la longueur du préfixe (linéarité
 * du code) : elle est cachable via MatrixCache.
 */
final class Solver
{
    // Alphabet affine : offset 'O', coset = lettres H-W et h-w (URL-safe)
    public const OFFSET = 0x4F;
    public const BASIS  = [0x21, 0x38, 0x1B, 0x3C, 0x3B];
    public const SERIAL = 8;

    public string $baseUrl;

    /** @var int[] indices des caractères libres */
    public array $freeChars;

    /** @var string[] colonnes de la matrice génératrice (bitsets 3249 bits) */
    public array $cols = [];

    /** @var string[] composition de chaque colonne (bitsets nvars bits) */
    public array $comp = [];

    /** @var array<array{0:int,1:int}> [(position, colonne)] dans l'ordre des pivots */
    public array $pivots = [];

    /** @var int[] alphabet de 32 valeurs URL-safe pour la série */
    private array $coset;

    public function __construct(
        private readonly QArtSpec $spec,
        private readonly string $prefix,
        private readonly RandomSource $random,
    ) {
        $plen = strlen($prefix);
        if ($plen < 1 || $plen + self::SERIAL >= QArtSpec::CAPACITY) {
            throw new QArtException(sprintf(
                'préfixe invalide : %d caractères, maximum %d (série de %d comprise)',
                $plen, QArtSpec::CAPACITY - self::SERIAL - 1, self::SERIAL
            ));
        }
        if (preg_match('/[^\x21-\x7E]/', $prefix)) {
            throw new QArtException('le préfixe doit être en ASCII imprimable sans espace');
        }

        $coset = [self::OFFSET];
        foreach (self::BASIS as $v) {
            foreach ($coset as $c) {
                $coset[] = $c ^ $v;
            }
        }
        $this->coset = $coset;

        $chars = str_split($prefix);
        for ($i = count($chars); $i < QArtSpec::CAPACITY; $i++) {
            $chars[] = chr(self::OFFSET);
        }
        $this->baseUrl = implode('', $chars);
        $this->freeChars = range($plen + self::SERIAL, QArtSpec::CAPACITY - 1);
        $this->reseedSerial();
    }

    /**
     * (Re)tire la série aléatoire. Les colonnes et pivots restent valides :
     * la matrice génératrice est indépendante des valeurs de la série.
     */
    public function reseedSerial(): void
    {
        $plen = strlen($this->prefix);
        for ($i = 0; $i < self::SERIAL; $i++) {
            $this->baseUrl[$plen + $i] = chr($this->coset[$this->random->int(0, 31)]);
        }
    }

    /** Clé de cache de la matrice : version/ECC figées, seule la longueur du préfixe compte. */
    public function cacheKey(): string
    {
        return sprintf('qart-v10L-p%d-s%d-b%s', strlen($this->prefix), self::SERIAL, dechex(array_sum(self::BASIS)));
    }

    public function buildGenerator(?MatrixCache $cache = null): void
    {
        $nvars = count($this->freeChars) * count(self::BASIS);
        $compLen = intdiv($nvars + 7, 8);

        $cols = $cache?->get($this->cacheKey());
        if ($cols !== null && count($cols) === $nvars) {
            $this->cols = $cols;
        } else {
            $m0 = Oracle::render($this->baseUrl, 0);
            $i = 0;
            foreach ($this->freeChars as $k) {
                foreach (self::BASIS as $v) {
                    $u = $this->baseUrl;
                    $u[$k] = chr(ord($u[$k]) ^ $v);
                    $this->cols[$i] = Oracle::render($u, 0) ^ $m0;
                    $i++;
                }
            }
            $cache?->set($this->cacheKey(), $this->cols);
        }

        for ($i = 0; $i < $nvars; $i++) {
            $c = str_repeat("\0", $compLen);
            Bits::set($c, $i, 1);
            $this->comp[$i] = $c;
        }
    }

    /**
     * Élimination gaussienne, pivots par ordre d'importance décroissante.
     *
     * @param int[] $order positions de modules (r*N+c) triées par importance
     */
    public function eliminate(array $order): void
    {
        $nvars = count($this->cols);
        $unused = array_fill(0, $nvars, true);
        $remaining = $nvars;
        $this->pivots = [];
        foreach ($order as $pos) {
            $bi = $pos >> 3;
            $bm = 0x80 >> ($pos & 7);
            $piv = -1;
            for ($j = 0; $j < $nvars; $j++) {
                if ($unused[$j] && (ord($this->cols[$j][$bi]) & $bm)) {
                    $piv = $j;
                    break;
                }
            }
            if ($piv < 0) {
                continue;
            }
            $unused[$piv] = false;
            for ($j = $piv + 1; $j < $nvars; $j++) {
                if ($unused[$j] && (ord($this->cols[$j][$bi]) & $bm)) {
                    $this->cols[$j] ^= $this->cols[$piv];
                    $this->comp[$j] ^= $this->comp[$piv];
                }
            }
            $this->pivots[] = [$pos, $piv];
            if (--$remaining === 0) {
                break;
            }
        }
    }

    /** @return array{0:string,1:string} [modules prédits (packés), URL] pour un masque donné */
    public function solve(string $targetPacked, int $mask): array
    {
        $cur = Oracle::render($this->baseUrl, $mask);
        $sol = str_repeat("\0", strlen($this->comp[0]));
        foreach ($this->pivots as [$pos, $piv]) {
            if (Bits::get($cur, $pos) !== Bits::get($targetPacked, $pos)) {
                $cur ^= $this->cols[$piv];
                $sol ^= $this->comp[$piv];
            }
        }
        $url = $this->baseUrl;
        $nb = count(self::BASIS);
        foreach ($this->freeChars as $i => $k) {
            $v = ord($url[$k]);
            foreach (self::BASIS as $j => $basis) {
                if (Bits::get($sol, $i * $nb + $j)) {
                    $v ^= $basis;
                }
            }
            $url[$k] = chr($v);
        }

        return [$cur, $url];
    }
}
