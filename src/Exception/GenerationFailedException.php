<?php

declare(strict_types=1);

namespace SqrArt\QArt\Exception;

/** Aucune tentative n'a produit un QR décodable : rien ne doit sortir. */
class GenerationFailedException extends QArtException
{
}
