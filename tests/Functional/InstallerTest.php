<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Pimcore;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;

final class InstallerTest extends FunctionalTestCase
{
    public function testInstallCreatesTheProposalTableAndUninstallDropsIt(): void
    {
        $installer = Pimcore::getKernel()->getBundle('TsfGatekeeperAiBundle')->getInstaller();
        self::assertNotNull($installer);
        self::assertTrue($installer->isInstalled());
        self::assertTrue($this->tableExists());

        try {
            $installer->uninstall();
            self::assertFalse($installer->isInstalled());
            self::assertFalse($this->tableExists());
        } finally {
            // the other tests rely on the table
            $installer->install();
        }

        self::assertTrue($installer->isInstalled());
        self::assertTrue($this->tableExists());
    }

    private function tableExists(): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist([ProposalStore::TABLE]);
    }
}
