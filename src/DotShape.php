<?php

declare(strict_types=1);

namespace SqrArt\QArt;

/** Forme des points de données (le coeur DxD de chaque module). */
enum DotShape: string
{
    case Square = 'square';
    case Round = 'round';
    case Diamond = 'diamond';
}
