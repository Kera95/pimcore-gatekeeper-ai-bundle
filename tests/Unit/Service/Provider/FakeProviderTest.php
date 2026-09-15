<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Provider;

use Codeception\Test\Unit;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Model\EnrichmentResponse;
use Tsf\GatekeeperAiBundle\Model\Usage;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Provider\FakeProvider;
use Tsf\GatekeeperAiBundle\Service\Provider\ProviderException;
use Tsf\GatekeeperAiBundle\Service\TokenEstimator;

final class FakeProviderTest extends Unit
{
    public function testGeneratesOneStandInValuePerSchemaProperty(): void
    {
        $provider = $this->provider();
        $request = new EnrichmentRequest(['instructions', 'kb'], 'object', [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Title: single-line text.'],
                'product_type' => ['type' => 'string', 'enum' => ['simple', 'configurable'], 'description' => 'Product type: one of.'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['new', 'sale']], 'description' => 'Tags: list.'],
            ],
            'required' => ['title', 'product_type', 'tags'],
            'additionalProperties' => false,
        ]);

        $response = $provider->generate($request);

        self::assertSame(['title' => 'Proposed title', 'product_type' => 'simple', 'tags' => ['new']], $response->getValues());
        self::assertTrue($response->isUsable());
        self::assertSame('claude-opus-5', $response->getModel());
        self::assertSame('fake-' . substr($request->getPrefixHash(), 0, 8), $response->getRequestId());
        self::assertGreaterThan(0, $response->getUsage()->getCacheReadTokens(), 'the prefix counts as a cache read');
        self::assertSame([$request], $provider->getRequests());
        self::assertSame('fake', $provider->getName());
    }

    public function testQueuedResponsesAndExceptionsComeFirst(): void
    {
        $provider = $this->provider();
        $canned = new EnrichmentResponse(['title' => 'Canned'], Usage::none(), 'm', EnrichmentResponse::STOP_END_TURN);
        $provider->queue($canned, ProviderException::invalidResponse('boom'));
        $request = new EnrichmentRequest(['p'], 'u', ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false]);

        self::assertSame($canned, $provider->generate($request));

        $this->expectException(ProviderException::class);
        $provider->generate($request);
    }

    public function testCountsTokensFromTheEstimate(): void
    {
        $provider = $this->provider();

        self::assertSame((int) ceil((strlen('a') + 1 + strlen('bb')) / 4), $provider->countTokens(new EnrichmentRequest(['a'], 'bb', [])));
    }

    private function provider(): FakeProvider
    {
        return new FakeProvider(new Settings((new Processor())->processConfiguration(new Configuration(), [[]])), new TokenEstimator());
    }
}
