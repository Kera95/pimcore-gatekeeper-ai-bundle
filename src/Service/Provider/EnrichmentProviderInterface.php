<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Provider;

use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Model\EnrichmentResponse;

/**
 * An LLM backend: generates the values of one request and counts the tokens of one. The
 * Anthropic implementation is the real one; the fake one serves tests and dry runs.
 */
interface EnrichmentProviderInterface
{
    public function getName(): string;

    public function getModel(): string;

    /**
     * @throws ProviderException when no usable response could be obtained (after retries)
     */
    public function generate(EnrichmentRequest $request): EnrichmentResponse;

    /**
     * Exact input token count of the request as the model would see it, without generating
     *
     * @throws ProviderException
     */
    public function countTokens(EnrichmentRequest $request): int;
}
