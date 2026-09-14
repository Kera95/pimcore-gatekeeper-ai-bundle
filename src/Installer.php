<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle;

use Doctrine\DBAL\Connection;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;

class Installer extends SettingsStoreAwareInstaller
{
    public function __construct(
        BundleInterface $bundle,
        private readonly Connection $connection,
    ) {
        parent::__construct($bundle);
    }

    public function install(): void
    {
        $this->connection->executeStatement(ProposalStore::createTableSql());

        parent::install();
    }

    public function uninstall(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . ProposalStore::TABLE);

        parent::uninstall();
    }

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }
}
