<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service;

/**
 * Rough token count without a tokenizer: four characters per token, the usual rule of thumb for
 * English and product copy. Good enough for size warnings and estimates; the API reports the
 * real numbers with every response.
 */
final class TokenEstimator
{
    public const CHARS_PER_TOKEN = 4;

    public function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
