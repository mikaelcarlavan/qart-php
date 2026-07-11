<?php

declare(strict_types=1);

namespace SqrArt\QArt;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use SqrArt\QArt\Cache\MatrixCache;
use SqrArt\QArt\Exception\GenerationFailedException;
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
    ) {
        $this->random = $random ?? new SystemRandom();
        if ($errorBudgetPerBlock < 0 || $errorBudgetPerBlock > 4) {
            throw new QArtException('errorBudgetPerBlock doit être entre 0 et 4');
        }
        if ($maxAttempts < 1) {
            throw new QArtException('maxAttempts doit être >= 1');
        }
    }

    public function generate(string $imagePath, string $outPng, ?RenderProfile $profile = null): GenerationResult
    {
        $profile ??= RenderProfile::screen();
        $spec = new QArtSpec();
        $img = ImagePipeline::fromFile($imagePath);
        $n = QArtSpec::N;

        // Cible packée + ordre d'importance (confiance décroissante)
        $tp = str_repeat("\0", intdiv($n * $n + 7, 8));
        $prio = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $p = $r * $n + $c;
                if ($img->target[$r][$c]) {
                    Bits::set($tp, $p, 1);
                }
                if (!$spec->fmap[$r][$c]) {
                    $prio[] = [$img->conf[$r][$c], $p];
                }
            }
        }
        usort($prio, fn ($a, $b) => $b[0] <=> $a[0]);
        $order = array_column($prio, 1);

        $solver = new Solver($spec, $this->prefix, $this->random);
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

            if (!$this->validateDecode || $this->decodesTo($outPng, $url)) {
                $plen = strlen($this->prefix);

                return new GenerationResult(
                    url: $url,
                    suffix: substr($url, $plen),
                    serial: substr($url, $plen, Solver::SERIAL),
                    mask: $mask,
                    pngPath: $outPng,
                    attempts: $attempt,
                    warnings: $img->warnings,
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
        $n = QArtSpec::N;
        $best = null;
        for ($mask = 0; $mask < 8; $mask++) {
            [$cur, $url] = $solver->solve($targetPacked, $mask);
            if (Oracle::render($url, $mask) !== $cur) {
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
        $n = QArtSpec::N;
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
        $count = [0, 0, 0, 0];
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
