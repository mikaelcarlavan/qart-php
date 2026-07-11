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
    ) {
        $this->random = $random ?? new SystemRandom;
        if ($errorBudgetPerBlock < 0 || $errorBudgetPerBlock > 4) {
            throw new QArtException('errorBudgetPerBlock doit être entre 0 et 4');
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
     */
    public function generate(
        string $imagePath,
        string $outPng,
        ?RenderProfile $profile = null,
        ?string $outSvg = null,
    ): GenerationResult {
        $profile ??= RenderProfile::screen();
        $spec = new QArtSpec($this->version);
        $img = ImagePipeline::fromFile($imagePath, $spec->n);
        $n = $spec->n;

        // Cible packée + ordre d'importance (confiance décroissante)
        $tp = str_repeat("\0", intdiv($n * $n + 7, 8));
        $prio = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $p = $r * $n + $c;
                if ($img->target[$r][$c]) {
                    Bits::set($tp, $p, 1);
                }
                if (! $spec->fmap[$r][$c]) {
                    $prio[] = [$img->conf[$r][$c], $p];
                }
            }
        }
        usort($prio, fn ($a, $b) => $b[0] <=> $a[0]);
        $order = array_column($prio, 1);

        $solver = new Solver($spec, $this->prefix, $this->random, $this->urlMode);
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

            [$mask, $url, $cur] = $this->bestMask($solver, $spec, $img, $tp);
            $matrix = $this->applyErrorBudget($spec, $img, $cur, $budget);

            Renderer::colorHalftone($img, $spec, $matrix, $outPng, $profile);

            if (! $this->validateDecode || $this->decodesTo($outPng, $url)) {
                if ($outSvg !== null) {
                    SvgRenderer::toFile($img, $spec, $matrix, $outSvg, $profile);
                }
                $plen = strlen($this->prefix);

                return new GenerationResult(
                    url: $url,
                    suffix: substr($url, $plen),
                    serial: substr($url, $plen, Solver::SERIAL),
                    mask: $mask,
                    pngPath: $outPng,
                    attempts: $attempt,
                    warnings: $img->warnings,
                    svgPath: $outSvg,
                );
            }
        }

        @unlink($outPng);
        throw new GenerationFailedException(sprintf(
            'aucun QR décodable après %d tentative(s) — image probablement trop hostile au halftone',
            $this->maxAttempts
        ));
    }

    /** @return array{0:int,1:string,2:string} [masque, URL, modules packés] au meilleur score pondéré */
    private function bestMask(Solver $solver, QArtSpec $spec, ImagePipeline $img, string $targetPacked): array
    {
        $n = $spec->n;
        $best = null;
        for ($mask = 0; $mask < 8; $mask++) {
            [$cur, $url] = $solver->solve($targetPacked, $mask);
            // En mode Short la solution vit dans le padding, que l'oracle ne
            // sait pas reproduire : la validation se fait par décodage final.
            if ($this->urlMode === UrlMode::Full && Oracle::render($url, $mask, $spec->version) !== $cur) {
                throw new QArtException("masque $mask : prédiction != rendu réel (mapping incohérent)");
            }
            $score = 0.0;
            for ($r = 0; $r < $n; $r++) {
                for ($c = 0; $c < $n; $c++) {
                    if (Bits::get($cur, $r * $n + $c) === $img->target[$r][$c]) {
                        $score += $img->conf[$r][$c];
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
     * visibles, en forçant leurs modules à la cible image.
     *
     * @return int[][] matrice de modules finale
     */
    private function applyErrorBudget(QArtSpec $spec, ImagePipeline $img, string $cur, int $budget): array
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
                    $g += $img->conf[$r][$c];
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
