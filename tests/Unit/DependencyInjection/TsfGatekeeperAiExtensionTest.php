<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\DependencyInjection;

use Codeception\Test\Unit;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tsf\GatekeeperAiBundle\Command\ValidateCommand;
use Tsf\GatekeeperAiBundle\DependencyInjection\TsfGatekeeperAiExtension;
use Tsf\GatekeeperAiBundle\Installer;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Provider\Anthropic\AnthropicProvider;
use Tsf\GatekeeperAiBundle\Service\Provider\EnrichmentProviderInterface;
use Tsf\GatekeeperAiBundle\Service\Provider\FakeProvider;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;

final class TsfGatekeeperAiExtensionTest extends Unit
{
    public function testLoadRegistersServicesAndParameters(): void
    {
        $container = new ContainerBuilder();
        (new TsfGatekeeperAiExtension())->load([['classes' => ['Product' => ['enrich' => ['title']]]]], $container);

        self::assertTrue($container->hasDefinition(Settings::class));
        self::assertTrue($container->hasDefinition(ProposalStore::class));
        self::assertTrue($container->hasDefinition(ValidateCommand::class));
        self::assertTrue($container->hasDefinition(Installer::class));
        self::assertTrue($container->getDefinition(Installer::class)->isPublic());

        $config = $container->getDefinition(Settings::class)->getArgument('$config');
        self::assertSame(['title'], $config['classes']['Product']['enrich']);
        self::assertSame($config, $container->getParameter('tsf_gatekeeper_ai.config'));
        self::assertSame(AnthropicProvider::class, (string) $container->getAlias(EnrichmentProviderInterface::class));
    }

    public function testTheFakeProviderCanBeSelected(): void
    {
        $container = new ContainerBuilder();
        (new TsfGatekeeperAiExtension())->load([['provider' => 'fake']], $container);

        self::assertSame(FakeProvider::class, (string) $container->getAlias(EnrichmentProviderInterface::class));
    }
}
