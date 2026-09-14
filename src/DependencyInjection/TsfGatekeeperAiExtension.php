<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;

final class TsfGatekeeperAiExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('tsf_gatekeeper_ai.config', $config);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container->getDefinition(Settings::class)
            ->setArgument('$config', $config);
    }
}
