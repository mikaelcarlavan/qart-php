<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use SqrArt\QArt\Cache\MatrixCache;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\Native\Gf2;
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
        public readonly int $serialLength = self::SERIAL,
    ) {
        if ($serialLength < 0 || $serialLength > 16) {
            throw new QArtException("longueur de série invalide: $serialLength (0..16)");
        }
        if ($serialLength === 0 && $mode !== UrlMode::Short) {
            throw new QArtException('payload statique (série 0) : uniquement en UrlMode::Short');
        }
        $plen = strlen($prefix);
        if ($plen < 1 || $plen + $serialLength >= $spec->capacity) {
            throw new QArtException(sprintf(
                'préfixe invalide : %d caractères, maximum %d pour la version %d (série de %d comprise)',
                $plen, $spec->capacity - $serialLength - 1, $spec->version, $serialLength
            ));
        }
        // payload statique : contenu libre (WiFi, vCard, EPC — espaces et
        // retours ligne compris) ; sinon la règle URL historique
        if ($serialLength > 0 && preg_match('/[^\x21-\x7E]/', $prefix)) {
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
            $this->freeChars = range($plen + $serialLength, $spec->capacity - 1);
        } else {
            $this->baseUrl = $prefix.str_repeat(chr(self::OFFSET), $serialLength);
            $start = self::firstFreeBit($spec, $plen, $serialLength);
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
    public static function firstFreeBit(QArtSpec $spec, int $prefixLen, int $serialLength = self::SERIAL): int
    {
        $contentEnd = $spec->headerBits + 8 * ($prefixLen + $serialLength) + 4;   // + terminator

        return intdiv($contentEnd + 7, 8) * 8;
    }

    /**
     * Mode Short : par linéarité, la colonne d'un bit du flux de données ne
     * dépend PAS de la longueur du préfixe — une seule sonde par
     * (version, ECC) couvre toutes les longueurs de payload (clé partagée),
     * chaque instance découpe sa plage. Décisif pour les payloads statiques
     * (WiFi…) dont la longueur varie par utilisateur.
     */
    private function sharedShortKey(): string
    {
        return sprintf('qart-shortbits-v%d%s-b%s', $this->spec->version, $this->spec->ecc->value, dechex(array_sum(self::BASIS)));
    }

    /**
     * (Re)tire la série aléatoire. Les colonnes et pivots restent valides :
     * la matrice génératrice est indépendante des valeurs de la série.
     */
    public function reseedSerial(): void
    {
        $plen = strlen($this->prefix);
        for ($i = 0; $i < $this->serialLength; $i++) {
            $this->baseUrl[$plen + $i] = chr($this->coset[$this->random->int(0, 31)]);
        }
    }

    /** Clé de cache de la matrice : dépend de la version, du mode et de la longueur du préfixe. */
    public function cacheKey(): string
    {
        return sprintf(
            'qart-v%d%s-%s-p%d-s%d-b%s',
            $this->spec->version, $this->spec->ecc->value, $this->mode->value, strlen($this->prefix), $this->serialLength,
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
            $m0 = Oracle::render($this->baseUrl, 0, $this->spec->version, $this->spec->ecc);
            $i = 0;
            foreach ($this->freeChars as $k) {
                foreach (self::BASIS as $v) {
                    $u = $this->baseUrl;
                    $u[$k] = chr(ord($u[$k]) ^ $v);
                    $this->cols[$i] = Oracle::render($u, 0, $this->spec->version, $this->spec->ecc) ^ $m0;
                    $i++;
                }
            }
            $cache?->set($this->cacheKey(), $this->cols);
        } else {
            // Sonde pleine capacité : flipper le bit b du caractère k flippe
            // exactement le bit header + 8k + b du flux de données, et la
            // colonne résultante est indépendante du contenu (linéarité) —
            // et indépendante de la longueur du préfixe : sonde partagée.
            $spec = $this->spec;
            $allStart = self::firstFreeBit($spec, 1, 0);
            $allEnd = $spec->headerBits + 8 * $spec->capacity;
            $all = $cache?->get($this->sharedShortKey());
            if ($all === null || count($all) !== $allEnd - $allStart) {
                $probe = str_repeat(chr(self::OFFSET), $spec->capacity);
                $m0 = Oracle::render($probe, 0, $spec->version, $spec->ecc);
                $all = [];
                for ($bit = $allStart; $bit < $allEnd; $bit++) {
                    $k = ($bit - $spec->headerBits) >> 3;
                    $b = ($bit - $spec->headerBits) & 7;
                    $u = $probe;
                    $u[$k] = chr(ord($u[$k]) ^ (0x80 >> $b));
                    $all[] = Oracle::render($u, 0, $spec->version, $spec->ecc) ^ $m0;
                }
                $cache?->set($this->sharedShortKey(), $all);
            }
            $this->cols = array_slice($all, $this->freeBits[0] - $allStart, $nvars);
        }

        for ($i = 0; $i < $nvars; $i++) {
            $c = str_repeat("\0", $compLen);
            Bits::set($c, $i, 1);
            $this->comp[$i] = $c;
        }
    }

    /**
     * Élimination gaussienne, pivots par ordre d'importance décroissante.
     * Si la librairie native est disponible (voir Native\Gf2), elle prend
     * le relais — résultat identique octet pour octet, ~100x plus rapide.
     *
     * @param  int[]  $order  positions de modules (r*N+c) triées par importance
     * @param  bool|null  $native  forcer (true) ou interdire (false) le
     *                             chemin natif ; null = automatique
     */
    public function eliminate(array $order, ?bool $native = null): void
    {
        if ($native ?? Gf2::available()) {
            if (! Gf2::available()) {
                throw new QArtException('librairie native indisponible : bâtir native/ (cargo build --release) ou définir QART_GF2_LIB');
            }
            $this->pivots = Gf2::eliminate($this->cols, $this->comp, $order);

            return;
        }
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
        $cur = Oracle::render($this->baseUrl, $mask, $this->spec->version, $this->spec->ecc);
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
