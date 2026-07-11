# sqrart/qart

QR codes artistiques : l'image est encodée dans les bits de données **et**
Reed-Solomon du QR (approche QArt), rendue en halftone couleur. L'URL
décodée = préfixe fixe + identifiant unique (série + solution) pour lookup
serveur.

**Multi-versions : QR v1 à v40, ECC L.** La version détermine la grille
(17 + 4×v modules de côté) et la capacité (v5 : 106 caractères, v10 : 271,
v40 : 2953). Les tables par version (blocs Reed-Solomon, alignement) sont
dérivées de chillerlan — la même librairie qui sert d'oracle.

```php
new QArtGenerator(prefix: 'https://sqr.art/', version: 5);   // logos simples
QArtGenerator::suggestVersion('photo.jpg');                  // heuristique 5|10|15
```

Coût et mémoire croissent vite avec la version : v5 < 1 s, v10 ~1 s (cache
chaud), v15 ~20 s à froid et ~400 Mo. Au-delà de v20, l'élimination
gaussienne en PHP pur devient prohibitive (voir la piste FFI/Rust de la
feuille de route) — la démo se limite à v5/v10/v15.

## Prérequis

- PHP >= 8.2, extension GD, `memory_limit` >= 512M pendant la génération
- `chillerlan/php-qrcode` ^5.0 (installé par Composer)

## Usage

```php
use SqrArt\QArt\Cache\FileMatrixCache;
use SqrArt\QArt\QArtGenerator;
use SqrArt\QArt\RenderProfile;

$generator = new QArtGenerator(
    prefix: 'https://sqr.art/',
    errorBudgetPerBlock: 1,                                   // 0-4 codewords sacrifiés par bloc RS
    matrixCache: new FileMatrixCache('/tmp/qart-cache'),      // ~4 s économisées par génération
    maxAttempts: 3,                                           // retry avec nouvelle série si décodage KO
);

$result = $generator->generate('photo.jpg', 'qr.png', RenderProfile::screen());

$result->url;       // URL complète encodée (271 caractères)
$result->suffix;    // à enregistrer en base pour le lookup GET /{suffix}
$result->serial;    // 8 premiers caractères du suffixe (40 bits d'entropie)
$result->mask;      // masque QR retenu (meilleur score de fidélité)
$result->attempts;  // 1 sauf si une régénération a été nécessaire
$result->warnings;  // image agrandie, contraste faible…
```

**Aucun QR invalide ne peut sortir** : le PNG produit est décodé (port ZXing
embarqué dans chillerlan) et l'URL vérifiée. En cas d'échec, régénération
avec une autre série et un budget d'erreur réduit, puis
`GenerationFailedException`.

### Mode URL courte (padding bits)

Par défaut, l'URL remplit toute la capacité du QR (`UrlMode::Full`). En
`UrlMode::Short`, seule l'URL courte (préfixe + série de 8) est encodée avec
un terminator précoce, et **les octets de padding portent l'image** —
l'approche du QArt original et de fuqr :

```php
use SqrArt\QArt\UrlMode;

$gen = new QArtGenerator(prefix: 'https://sqr.art/', urlMode: UrlMode::Short);
$res = $gen->generate('photo.jpg', 'qr.png');
$res->url;      // https://sqr.art/UKmohnVJ — 24 caractères, propre au scan
$res->suffix;   // la série seule (8 caractères) pour le lookup
```

Doubles gains : URL décodée lisible ET meilleure fidélité (8 bits de liberté
par octet de padding contre 5 par caractère d'URL — 1972 vs 1235 variables
en v10 avec ce préfixe). Les décodeurs ignorent le contenu du padding
(vérifié par décodage réel à chaque génération) ; comme pour le reste,
valider sur vos appareils cibles avant un grand tirage.

### Sortie SVG (print-ready)

Passer un 4e argument à `generate()` produit aussi un SVG vectoriel —
imprimable à toute taille sans artefact, même matrice que le PNG (qui reste
la référence validée par décodage) :

```php
$result = $generator->generate('photo.jpg', 'qr.png', $profile, 'qr.svg');
$result->svgPath; // 'qr.svg'
```

Le poids est maîtrisé par fusion des sous-pixels en plages horizontales
(couleurs quantifiées à 32 niveaux par canal, imperceptible).

### Styles de points et finders

```php
use SqrArt\QArt\{DotShape, FinderShape};

$profile = RenderProfile::screen()
    ->withDotShape(DotShape::Round)          // Square | Round | Diamond
    ->withFinderShape(FinderShape::Rounded)  // Square | Rounded
    ->withFinderColor('#1a2b4c');            // couleur de marque sombre
```

La couleur de finder est contrainte en luminance (<= 0.35) : une couleur
trop claire casserait la détection et est refusée (`QArtException`). Les
styles s'appliquent aux deux sorties (PNG et SVG).

### Profils de rendu

- `RenderProfile::screen()` — luminances douces, affichage écran (défaut) ;
- `RenderProfile::print()` — points plus contrastés, échelle supérieure ;
  l'impression CMJN écrase les nuances. **À recalibrer sur matrice de tests
  terrain** (Phase 2 du plan) avant un tirage sérieux.

### Tests reproductibles

Le générateur aléatoire est injectable : avec `SeededRandom`, la sortie est
octet pour octet déterministe (golden tests).

```php
use SqrArt\QArt\Random\SeededRandom;

new QArtGenerator(prefix: 'https://sqr.art/', random: new SeededRandom(42));
```

## Structure

- `QArtSpec`      : géométrie QR v10-L (modules de fonction, zigzag, entrelacement)
- `Oracle`        : rendu conforme via chillerlan (version/ECC/masque figés)
- `ImagePipeline` : GD, formats JPEG/PNG/WebP/GIF, alpha aplati, autocontraste,
                    dithering Atkinson, cibles/confiance par module
- `Solver`        : matrice génératrice empirique + élimination gaussienne GF(2)
                    avec pivots par importance visuelle ; série seedable
- `Cache/*`       : cache de la matrice génératrice (dépend uniquement de la
                    longueur du préfixe — linéarité du code)
- `Renderer`      : halftone 7×7 sous-pixels, points 3×3, couleur contrainte
                    en luminance (teinte libre, luminance imposée)
- `QArtGenerator` : orchestration, budget d'erreur, validation par décodage

## Tests

```bash
composer install
composer test
```

La suite couvre : mapping bit→module contre le rendu réel (0 erreur sur les
8 masques), comptage zigzag (2768), bijection d'entrelacement, alphabet
affine (32 valeurs URL-safe H-W/h-w), cas limites d'image, génération de
bout en bout validée par décodage, déterminisme et cache.

## Limites connues

- ECC figé à L. Multi-ECC (M/Q/H) : voir la feuille de route v2.
- La zone préfixe/header (bas droite) reste structurellement non contrôlable.
- Taille physique minimale d'impression recommandée : ~4×4 cm à 300 dpi
  (le halftone exige ~3× plus de résolution qu'un QR standard).
- Tester sur smartphones réels (écran + papier) avant production.
