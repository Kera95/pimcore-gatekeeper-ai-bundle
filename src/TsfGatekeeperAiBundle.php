<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle;

use Composer\InstalledVersions;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Tsf\GatekeeperAiBundle\DependencyInjection\TsfGatekeeperAiExtension;

use function dirname;

class TsfGatekeeperAiBundle extends AbstractPimcoreBundle
{
    public function getNiceName(): string
    {
        return 'TSF Gatekeeper AI Bundle';
    }

    public function getDescription(): string
    {
        return 'Proposes values for the fields the Gatekeeper reports missing, from a Markdown knowledge base, for review and apply by console command.';
    }

    public function getComposerPackageName(): string
    {
        return 'kerimkaralic/pimcore-gatekeeper-ai-bundle';
    }

    public function getVersion(): string
    {
        if (!InstalledVersions::isInstalled($this->getComposerPackageName())) {
            return '';
        }

        return ltrim((string) InstalledVersions::getPrettyVersion($this->getComposerPackageName()), 'v');
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new TsfGatekeeperAiExtension();
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function getInstaller(): Installer
    {
        return $this->container->get(Installer::class);
    }
}
