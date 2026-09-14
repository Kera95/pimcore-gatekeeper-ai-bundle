<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Tsf\GatekeeperAiBundle\Service\Prompt\SampleRequestFactory;
use Tsf\GatekeeperAiBundle\Service\Provider\EnrichmentProviderInterface;
use Tsf\GatekeeperAiBundle\Service\Provider\FakeProvider;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;

/**
 * The test kernel runs with provider "fake", so --live exercises the whole path without a network
 */
final class LiveValidationTest extends FunctionalTestCase
{
    public function testTheSampleRequestUsesTheFirstClassAndItsEnrichableFields(): void
    {
        /** @var SampleRequestFactory $factory */
        $factory = $this->service(SampleRequestFactory::class);

        $request = $factory->create();

        self::assertCount(3, $request->getPrefixBlocks());
        self::assertStringStartsWith("# Fields to produce (language: en)\n", $request->getPrefixBlocks()[2]);
        self::assertSame(['title', 'description', 'seo_title'], $request->getSchema()['required']);
        self::assertStringContainsString('Object of class GkProduct (language: en).', $request->getUserMessage());
    }

    public function testValidateLiveReportsTheSampleRequestSize(): void
    {
        /** @var EnrichmentProviderInterface $provider */
        $provider = $this->service(EnrichmentProviderInterface::class);
        self::assertInstanceOf(FakeProvider::class, $provider);

        $tester = $this->runCommand('tsf:gatekeeper:ai:validate', ['--live' => true]);
        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('OK    live check via fake: key valid, model claude-opus-5 reachable, sample request', $output);
        self::assertStringContainsString('prefix hash ', $output);
        self::assertStringContainsString('prompt caching inactive: the prefix is below the floor (floor 512 tokens for claude-opus-5)', $output, 'no knowledge base in the test kernel, so the sample prefix is small');
        self::assertStringContainsString('The configuration is valid and the API accepts it.', $output);
    }
}
