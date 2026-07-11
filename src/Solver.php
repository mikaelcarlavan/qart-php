<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Cache\MatrixCache;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\Random\RandomSource;

/**
 * Solveur GF(2) : matrice génératrice empirique + élimination gaussienne
 * avec pivots par importance visuelle.
 *
 * Deux jeux de variables selon le mode :
 * - Full : 5 bits (coset URL-safe) par caractère libre de l'URL ;
 * - Short : 8 bits par octet de padding après le terminator. Les colonnes
 *   sont sondées via des URLs pleine capacité : le pipeline QR (RS,
 *   entrelacement, placement, masque) étant linéaire sur GF(2), la colonne
 *   d'un bit du flux de données ne dépend pas du reste du contenu.
 *
 * La matrice ne dépend que de (version, mode, longueur du préfixe) :
 * cachable via MatrixCache.
 */
final class Solver
{
    // Alphabet affine : offset 'O', coset = lettres H-W et h-w (URL-safe)
    public const OFFSET = 0x4F;

    public const BASIS = [0x21, 0x38, 0x1B, 0x3C, 0x3B];

    public const SERIAL = 8;

    public string $baseUrl;

    /** @var int[] mode Full : indices des caractères libres */
    public array $freeChars = [];

    /** @var int[] mode Short : positions de bits libres dans le flux de données */
    public array $freeBits = [];

    /** @var string[] colonnes de la matrice génératrice (bitsets n*n bits) */
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
        private readonly UrlMode $mode = UrlMode::Full,
    ) {
        $plen = strlen($prefix);
        if ($plen < 1 || $plen + self::SERIAL >= $spec->capacity) {
            throw new QArtException(sprintf(
                'préfixe invalide : %d caractères, maximum %d pour la version %d (série de %d comprise)',
                $plen, $spec->capacity - self::SERIAL - 1, $spec->version, self::SERIAL
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

        if ($this->mode === UrlMode::Full) {
            $chars = str_split($prefix);
            for ($i = count($chars); $i < $spec->capacity; $i++) {
                $chars[] = chr(self::OFFSET);
            }
            $this->baseUrl = implode('', $chars);
            $this->freeChars = range($plen + self::SERIAL, $spec->capacity - 1);
        } else {
            $this->baseUrl = $prefix.str_repeat(chr(self::OFFSET), self::SERIAL);
            $start = self::firstFreeBit($spec, $plen);
            // les bits au-delà de header + 8*capacité sont hors de portée des
            // sondes pleine capacité (zone terminator) : on s'arrête avant
            $end = $spec->headerBits + 8 * $spec->capacity;
            if ($end - $start < 8) {
                throw new QArtException(sprintf(
                    'mode Short inutilisable en v%d avec ce préfixe : aucun octet de padding libre (utiliser UrlMode::Full)',
                    $spec->version
                ));
            }
            $this->freeBits = range($start, $end - 1);
        }
        $this->reseedSerial();
    }

    /** Premier bit de padding libre : après contenu + terminator, aligné à l'octet. */
    public static function firstFreeBit(QArtSpec $spec, int $prefixLen): int
    {
        $contentEnd = $spec->headerBits + 8 * ($prefixLen + self::SERIAL) + 4;   // + terminator

        return intdiv($contentEnd + 7, 8) * 8;
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

    /** Clé de cache de la matrice : dépend de la version, du mode et de la longueur du préfixe. */
    public function cacheKey(): string
    {
        return sprintf(
            'qart-v%dL-%s-p%d-s%d-b%s',
            $this->spec->version, $this->mode->value, strlen($this->prefix), self::SERIAL,
            dechex(array_sum(self::BASIS))
        );
    }

    public function buildGenerator(?MatrixCache $cache = null): void
    {
        $nvars = $this->mode === UrlMode::Full
            ? count($this->freeChars) * count(self::BASIS)
            : count($this->freeBits);
        $compLen = intdiv($nvars + 7, 8);

        $cols = $cache?->get($this->cacheKey());
        if ($cols !== null && count($cols) === $nvars) {
            $this->cols = $cols;
        } elseif ($this->mode === UrlMode::Full) {
            $m0 = Oracle::render($this->baseUrl, 0, $this->spec->version);
            $i = 0;
            foreach ($this->freeChars as $k) {
                foreach (self::BASIS as $v) {
                    $u = $this->baseUrl;
                    $u[$k] = chr(ord($u[$k]) ^ $v);
                    $this->cols[$i] = Oracle::render($u, 0, $this->spec->version) ^ $m0;
                    $i++;
                }
            }
            $cache?->set($this->cacheKey(), $this->cols);
        } else {
            // Sonde pleine capacité : flipper le bit b du caractère k flippe
            // exactement le bit header + 8k + b du flux de données, et la
            // colonne résultante est indépendante du contenu (linéarité).
            $probe = $this->baseUrl.str_repeat(chr(self::OFFSET), $this->spec->capacity - strlen($this->baseUrl));
            $m0 = Oracle::render($probe, 0, $this->spec->version);
            foreach ($this->freeBits as $i => $s) {
                $k = ($s - $this->spec->headerBits) >> 3;
                $b = ($s - $this->spec->headerBits) & 7;
                $u = $probe;
                $u[$k] = chr(ord($u[$k]) ^ (0x80 >> $b));
                $this->cols[$i] = Oracle::render($u, 0, $this->spec->version) ^ $m0;
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
     * @param  int[]  $order  positions de modules (r*N+c) triées par importance
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

    /**
     * @return array{0:string,1:string} [modules prédits (packés), URL] pour un masque donné.
     *                                  En mode Short l'URL ne change pas : la solution vit dans le padding.
     */
    public function solve(string $targetPacked, int $mask): array
    {
        $cur = Oracle::render($this->baseUrl, $mask, $this->spec->version);
        $sol = str_repeat("\0", strlen($this->comp[0]));
        foreach ($this->pivots as [$pos, $piv]) {
            if (Bits::get($cur, $pos) !== Bits::get($targetPacked, $pos)) {
                $cur ^= $this->cols[$piv];
                $sol ^= $this->comp[$piv];
            }
        }
        $url = $this->baseUrl;
        if ($this->mode === UrlMode::Full) {
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
        }

        return [$cur, $url];
    }
}
