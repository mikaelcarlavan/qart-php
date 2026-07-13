<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use SqrArt\QArt\Cache\MatrixCache;
use SqrArt\QArt\Exception\GenerationFailedException;
use SqrArt\QArt\Exception\ImageException;
use SqrArt\QArt\Exception\QArtException;
use SqrArt\QArt\Random\RandomSource;
use SqrArt\QArt\Random\SystemRandom;

/**
 * Orchestration : image -> cible/confiance -> solveur -> choix du masque ->
 * budget d'erreur -> rendu halftone -> validation par décodage réel.
 *
 * Aucun QR invalide ne peut sortir : le PNG produit est décodé (port ZXing
 * de chillerlan) et l'URL vérifiée ; en cas d'échec, nouvelle série et
 * budget d'erreur réduit, puis exception explicite.
 *
 * Payload statique (WiFi, vCard, EPC…) : serialLength 0 + UrlMode::Short —
 * prefix devient le contenu exact encodé (octets libres), pas de série ni
 * de résolution serveur ; les tentatives ne varient alors que par budget.
 */
final class QArtGenerator
{
    private RandomSource $random;

    public function __construct(
        private readonly string $prefix,
        private readonly int $errorBudgetPerBlock = 1,
        ?RandomSource $random = null,
        private readonly ?MatrixCache $matrixCache = null,
        private readonly int $maxAttempts = 3,
        private readonly bool $validateDecode = true,
        private readonly int $version = QArtSpec::DEFAULT_VERSION,
        private readonly UrlMode $urlMode = UrlMode::Full,
        private readonly Ecc $ecc = Ecc::L,
        private readonly int $serialLength = Solver::SERIAL,
    ) {
        $this->random = $random ?? new SystemRandom;
        if ($errorBudgetPerBlock < 0) {
            throw new QArtException('errorBudgetPerBlock doit être >= 0');
        }
        if ($maxAttempts < 1) {
            throw new QArtException('maxAttempts doit être >= 1');
        }
    }

    /**
     * Suggère une version QR selon la complexité de l'image (heuristique :
     * énergie de gradient sur une grille réduite). Une image détaillée
     * profite d'une grille plus fine ; une image simple scanne mieux en
     * version basse. Coût et mémoire croissent vite : plafonné à v15.
     */
    public static function suggestVersion(string $imagePath): int
    {
        $data = @file_get_contents($imagePath);
        $src = $data !== false ? @imagecreatefromstring($data) : false;
        if ($src === false) {
            throw new ImageException("image illisible: $imagePath");
        }
        $g = 64;
        $small = imagecreatetruecolor($g, $g);
        $side = min(imagesx($src), imagesy($src));
        imagecopyresampled($small, $src, 0, 0, 0, 0, $g, $g, $side, $side);
        $lum = [];
        for ($y = 0; $y < $g; $y++) {
            for ($x = 0; $x < $g; $x++) {
                $px = imagecolorat($small, $x, $y);
                $lum[$y][$x] = (0.299 * (($px >> 16) & 0xFF) + 0.587 * (($px >> 8) & 0xFF) + 0.114 * ($px & 0xFF)) / 255.0;
            }
        }
        $energy = 0.0;
        for ($y = 0; $y < $g - 1; $y++) {
            for ($x = 0; $x < $g - 1; $x++) {
                $energy += abs($lum[$y][$x + 1] - $lum[$y][$x]) + abs($lum[$y + 1][$x] - $lum[$y][$x]);
            }
        }
        $energy /= 2 * ($g - 1) * ($g - 1);

        return match (true) {
            $energy < 0.05 => 5,    // aplats, logos simples
            $energy < 0.11 => 10,   // photos standard
            default => 15,          // scènes très détaillées
        };
    }

    /**
     * @param  string|null  $outSvg  chemin de sortie SVG optionnel (vectoriel,
     *                               imprimable à toute taille) — même matrice
     *                               que le PNG, validé via le décodage du PNG
     * @param  ImportanceMap|null  $importance  zones protégées (pivots en
     *                                          premier) et carte peinte (boost)
     * @param  array{x:float,y:float,size:float}|null  $crop  recadrage carré
     *                                                        (défaut : centré)
     * @param  Fit  $fit  images non carrées : recadrer (Cover, défaut) ou
     *                    tout garder sur fond blanc (Contain)
     * @param  string|null  $outPdf  chemin de sortie PDF optionnel (vectoriel,
     *                               page de PdfRenderer::DEFAULT_SIZE_MM de
     *                               côté — PdfRenderer::toFile pour une autre
     *                               taille)
     */
    public function generate(
        string $imagePath,
        string $outPng,
        ?RenderProfile $profile = null,
        ?string $outSvg = null,
        ?ImportanceMap $importance = null,
        ?array $crop = null,
        ?string $outPdf = null,
        Fit $fit = Fit::Cover,
    ): GenerationResult {
        $profile ??= RenderProfile::screen();
        $spec = new QArtSpec($this->version, $this->ecc);
        // Reed-Solomon corrige au plus eccPerBlock/2 codewords par bloc :
        // garder de la marge pour les erreurs de lecture réelles
        $maxBudget = max(0, intdiv($spec->eccPerBlock, 2) - 2);
        if ($this->errorBudgetPerBlock > $maxBudget) {
            throw new QArtException(sprintf(
                'errorBudgetPerBlock %d trop élevé pour v%d-%s (max %d : %d codewords ECC par bloc)',
                $this->errorBudgetPerBlock, $this->version, $this->ecc->value, $maxBudget, $spec->eccPerBlock
            ));
        }
        $pixelArt = $profile->mode === RenderMode::Module;
        $img = ImagePipeline::fromFile($imagePath, $spec->n, $crop, $pixelArt, $profile->dithering, $fit);
        $n = $spec->n;
        $protected = $importance?->hasZones() ? $importance->moduleMask($spec) : null;
        $weights = $importance?->hasPaint() ? $importance->moduleWeights($spec) : null;

        // Cible packée + ordre d'importance : zones protégées d'abord, puis
        // confiance pondérée par la carte peinte
        $tp = str_repeat("\0", intdiv($n * $n + 7, 8));
        $prio = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $p = $r * $n + $c;
                if ($img->target[$r][$c]) {
                    Bits::set($tp, $p, 1);
                }
                if (! $spec->fmap[$r][$c]) {
                    $boost = 1.0 + ImportanceMap::PAINT_BOOST * ($weights[$r][$c] ?? 0.0);
                    $prio[] = [$protected[$r][$c] ?? false ? 1 : 0, $img->conf[$r][$c] * $boost, $p];
                }
            }
        }
        usort($prio, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $order = array_column($prio, 2);

        $solver = new Solver($spec, $this->prefix, $this->random, $this->urlMode, $this->serialLength);
        $solver->buildGenerator($this->matrixCache);
        $solver->eliminate($order);

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            if ($attempt > 1) {
                // Nouvelle série ; la matrice éliminée reste valide (linéarité)
                $solver->reseedSerial();
            }
            // Les erreurs sacrifiées sont la cause la plus probable d'un échec
            // de décodage : on réduit le budget à chaque nouvelle tentative.
            $budget = max(0, $this->errorBudgetPerBlock - ($attempt - 1));

            [$mask, $url, $cur] = $this->bestMask($solver, $spec, $img, $tp, $protected);
            $matrix = $this->applyErrorBudget($spec, $img, $cur, $budget, $protected, $weights);

            $pixelArt
                ? Renderer::pixelArt($img, $spec, $matrix, $outPng, $profile)
                : Renderer::colorHalftone($img, $spec, $matrix, $outPng, $profile);

            if (! $this->validateDecode || $this->decodesTo($outPng, $url)) {
                if ($outSvg !== null) {
                    SvgRenderer::toFile($img, $spec, $matrix, $outSvg, $profile);
                }
                if ($outPdf !== null) {
                    PdfRenderer::toFile($img, $spec, $matrix, $outPdf, $profile);
                }
                $plen = strlen($this->prefix);

                $protectedMismatches = null;
                $warnings = $img->warnings;
                if ($protected !== null) {
                    $protectedMismatches = 0;
                    for ($r = 0; $r < $n; $r++) {
                        for ($c = 0; $c < $n; $c++) {
                            if ($protected[$r][$c] && ! $spec->fmap[$r][$c]
                                && $matrix[$r][$c] !== $img->target[$r][$c]) {
                                $protectedMismatches++;
                            }
                        }
                    }
                    if ($protectedMismatches > 0) {
                        $warnings[] = sprintf(
                            'zone protégée : %d module(s) non garantis — réduire la zone, augmenter errorBudgetPerBlock ou passer en UrlMode::Short',
                            $protectedMismatches
                        );
                    }
                }

                return new GenerationResult(
                    url: $url,
                    suffix: substr($url, $plen),
                    serial: substr($url, $plen, $this->serialLength),
                    mask: $mask,
                    pngPath: $outPng,
                    attempts: $attempt,
                    warnings: $warnings,
                    svgPath: $outSvg,
                    protectedMismatches: $protectedMismatches,
                    pdfPath: $outPdf,
                );
            }
        }

        @unlink($outPng);
        throw new GenerationFailedException(sprintf(
            'aucun QR décodable après %d tentative(s) — image probablement trop hostile au halftone',
            $this->maxAttempts
        ));
    }

    /**
     * Meilleur masque au score pondéré. Les modules protégés pèsent
     * PROTECT_WEIGHT : les bits fixes (header, préfixe, série) valant
     * contenu XOR masque, le choix du masque est le seul levier sur les
     * modules protégés qui tombent dans la zone non contrôlable.
     *
     * @param  bool[][]|null  $protected  masque des modules protégés
     * @return array{0:int,1:string,2:string} [masque, URL, modules packés]
     */
    private function bestMask(Solver $solver, QArtSpec $spec, ImagePipeline $img, string $targetPacked, ?array $protected = null): array
    {
        $n = $spec->n;
        $best = null;
        for ($mask = 0; $mask < 8; $mask++) {
            [$cur, $url] = $solver->solve($targetPacked, $mask);
            // En mode Short la solution vit dans le padding, que l'oracle ne
            // sait pas reproduire : la validation se fait par décodage final.
            if ($this->urlMode === UrlMode::Full && Oracle::render($url, $mask, $spec->version, $spec->ecc) !== $cur) {
                throw new QArtException("masque $mask : prédiction != rendu réel (mapping incohérent)");
            }
            $score = 0.0;
            for ($r = 0; $r < $n; $r++) {
                for ($c = 0; $c < $n; $c++) {
                    if (Bits::get($cur, $r * $n + $c) === $img->target[$r][$c]) {
                        $score += ($protected[$r][$c] ?? false)
                            ? ImportanceMap::PROTECT_WEIGHT * (1.0 + $img->conf[$r][$c])
                            : $img->conf[$r][$c];
                    }
                }
            }
            if ($best === null || $score > $best[0]) {
                $best = [$score, $mask, $url, $cur];
            }
        }

        return [$best[1], $best[2], $best[3]];
    }

    /**
     * Sacrifie jusqu'à $budget codewords par bloc RS sur les pires erreurs
     * visibles, en forçant leurs modules à la cible image. Les modules de
     * zones protégées pèsent PROTECT_WEIGHT dans le tri : leurs codewords
     * sont corrigés en premier.
     *
     * @param  bool[][]|null  $protected  masque des modules protégés
     * @param  float[][]|null  $weights  carte peinte (0..1)
     * @return int[][] matrice de modules finale
     */
    private function applyErrorBudget(QArtSpec $spec, ImagePipeline $img, string $cur, int $budget, ?array $protected = null, ?array $weights = null): array
    {
        $n = $spec->n;
        $matrix = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $matrix[$r][$c] = Bits::get($cur, $r * $n + $c);
            }
        }
        if ($budget <= 0) {
            return $matrix;
        }
        $gains = [];
        foreach ($spec->codewordModules() as [$blk, $pos]) {
            $g = 0.0;
            foreach ($pos as [$r, $c]) {
                if ($matrix[$r][$c] !== $img->target[$r][$c]) {
                    // le terme constant garantit aussi les modules protégés
                    // à confiance quasi nulle (gris ambigus)
                    $g += ($protected[$r][$c] ?? false)
                        ? ImportanceMap::PROTECT_WEIGHT * (1.0 + $img->conf[$r][$c])
                        : $img->conf[$r][$c] * (1.0 + ImportanceMap::PAINT_BOOST * ($weights[$r][$c] ?? 0.0));
                }
            }
            if ($g > 0) {
                $gains[] = [$g, $blk, $pos];
            }
        }
        usort($gains, fn ($a, $b) => $b[0] <=> $a[0]);
        $count = array_fill(0, count($spec->blockSizes), 0);
        foreach ($gains as [$g, $blk, $pos]) {
            if ($count[$blk] >= $budget) {
                continue;
            }
            foreach ($pos as [$r, $c]) {
                $matrix[$r][$c] = $img->target[$r][$c];
            }
            $count[$blk]++;
        }

        return $matrix;
    }

    private function decodesTo(string $png, string $expectedUrl): bool
    {
        try {
            $result = (new QRCode(new QROptions([
                'readerUseImagickIfAvailable' => false,
            ])))->readFromFile($png);

            return $result->data === $expectedUrl;
        } catch (\Throwable) {
            return false;
        }
    }
}
