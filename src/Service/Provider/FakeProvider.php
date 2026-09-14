<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Provider;

use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Model\EnrichmentResponse;
use Tsf\GatekeeperAiBundle\Model\Usage;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\TokenEstimator;

use function count;
use function is_array;

/**
 * Answers without a network: a deterministic value per schema property (the first enum value,
 * or "Proposed <title>" for text), usage from the character estimate, no cost. For the test
 * suites and for dry runs; select it with tsf_gatekeeper_ai.provider: fake. Tests can queue
 * responses to be returned in order instead.
 */
final class FakeProvider implements EnrichmentProviderInterface
{
    public const NAME = 'fake';

    /**
     * @var array<int, EnrichmentResponse|ProviderException>
     */
    private array $queue = [];

    /**
     * @var EnrichmentRequest[]
     */
    private array $requests = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly TokenEstimator $estimator,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getModel(): string
    {
        return $this->settings->getModel();
    }

    public function generate(EnrichmentRequest $request): EnrichmentResponse
    {
        $this->requests[] = $request;

        if (count($this->queue) > 0) {
            $next = array_shift($this->queue);
            if ($next instanceof ProviderException) {
                throw $next;
            }

            return $next;
        }

        $values = [];
        foreach ($request->getSchema()['properties'] ?? [] as $path => $property) {
            $values[(string) $path] = $this->valueFor(is_array($property) ? $property : []);
        }
        $output = json_encode($values) ?: '';

        return new EnrichmentResponse(
            $values,
            new Usage($this->estimator->estimate($request->getUserMessage()), $this->estimator->estimate($output), $this->estimator->estimate($request->getPrefixText())),
            $this->getModel(),
            EnrichmentResponse::STOP_END_TURN,
            'fake-' . substr($request->getPrefixHash(), 0, 8)
        );
    }

    public function countTokens(EnrichmentRequest $request): int
    {
        $this->requests[] = $request;

        return $this->estimator->estimate($request->getPrefixText() . "\n" . $request->getUserMessage());
    }

    /**
     * The next generate() calls return these, in order, instead of the generated stand-in
     */
    public function queue(EnrichmentResponse|ProviderException ...$responses): void
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }
    }

    /**
     * @return EnrichmentRequest[] every request seen, in order
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function reset(): void
    {
        $this->queue = [];
        $this->requests = [];
    }

    /**
     * @param array<string, mixed> $property
     */
    private function valueFor(array $property): mixed
    {
        if (($property['type'] ?? null) === 'array') {
            $enum = $property['items']['enum'] ?? [];

            return is_array($enum) && count($enum) > 0 ? [(string) $enum[0]] : [];
        }
        if (is_array($property['enum'] ?? null) && count($property['enum']) > 0) {
            return (string) $property['enum'][0];
        }

        $description = (string) ($property['description'] ?? '');
        $title = trim((string) strstr($description, ':', true)) ?: 'value';

        return 'Proposed ' . strtolower($title);
    }
}
