<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/**
 * Stratégie d'encodage de l'URL dans le QR.
 *
 * - Full : l'URL remplit toute la capacité (préfixe + série + solution) ;
 *   padding 100 % standard, compatibilité décodeur maximale, mais l'URL
 *   décodée est longue et chaque caractère n'offre que 5 bits de liberté.
 * - Short : URL courte (préfixe + série) avec terminator précoce ; les
 *   octets de padding deviennent les variables libres (8 bits chacun).
 *   URL décodée propre ET meilleure fidélité. Les décodeurs ignorent le
 *   contenu du padding (approche QArt/fuqr) — validé par décodage réel à
 *   chaque génération, mais à confirmer sur le terrain comme tout le reste.
 */
enum UrlMode: string
{
    case Full = 'full';
    case Short = 'short';
}
