<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Support\Helper;

use Codeception\Module;
use Pimcore;
use Pimcore\Bootstrap;
use Pimcore\Event\TestEvents;
use Pimcore\Tests\Support\Util\Autoloader;
use Pimcore\Tests\Support\Util\TestHelper;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Tsf\GatekeeperAiBundle\Tests\Support\App\Kernel;
use Tsf\GatekeeperAiBundle\Tests\Support\Fixture\ClassFixtures;
use Tsf\GatekeeperAiBundle\Tests\Support\PimcoreStubKernel;

use function sprintf;

/**
 * Codeception module for the functional suite. Listed before Pimcore's own module, so that
 * _initialize() has prepared the project constants and environment by the time Pimcore's module
 * boots the kernel; _beforeSuite() then installs the bundles and creates the test classes in the
 * freshly created database.
 */
final class GatekeeperAi extends Module
{
    public function _initialize(): void
    {
        $dsn = getenv('PIMCORE_TEST_DB_DSN') ?: ($_SERVER['PIMCORE_TEST_DB_DSN'] ?? '');
        if ($dsn === '') {
            throw new \RuntimeException(
                'PIMCORE_TEST_DB_DSN is not set. The functional suite needs a database it may drop and ' .
                'recreate, e.g. PIMCORE_TEST_DB_DSN=mysql://root:root@127.0.0.1:3306/tsf_gatekeeper_ai_test. ' .
                'The unit suite needs no database: run "codecept run Unit".'
            );
        }

        $root = dirname(__DIR__) . '/App';
        $fs = new Filesystem();
        // a stale compiled container would ignore config changes (APP_DEBUG is off); the kernel
        // reads its cache directory from APP_CACHE_DIR when the environment sets one
        $fs->remove($_SERVER['APP_CACHE_DIR'] ?? $root . '/var/cache');
        $fs->mkdir([$root . '/var/config', $root . '/var/classes', $root . '/public/var']);

        foreach (['APP_ENV' => 'test', 'PIMCORE_TEST' => '1', 'PIMCORE_TEST_DB_DSN' => $dsn] as $name => $value) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        // Pimcore 2026 validates the product registration when the container is compiled, unless the
        // "needs install" marker is present. The test project is never registered and never installed
        // through Pimcore's installer, so the marker is always correct here - and it must be written
        // even when the environment happens to carry a product key of a surrounding project, whose
        // encryption secret and instance identifier this project does not have.
        $fs->touch($root . '/var/config/needs-install.lock');

        if (!defined('PIMCORE_PROJECT_ROOT')) {
            define('PIMCORE_PROJECT_ROOT', $root);
        }
        // the vendor directory is the bundle's own one or the surrounding project's, never App/vendor
        if (!defined('PIMCORE_COMPOSER_PATH')) {
            define('PIMCORE_COMPOSER_PATH', TSF_GATEKEEPER_AI_VENDOR_DIR);
        }
        if (!defined('PIMCORE_COMPOSER_FILE_PATH')) {
            define('PIMCORE_COMPOSER_FILE_PATH', dirname(TSF_GATEKEEPER_AI_VENDOR_DIR));
        }
        if (!defined('PIMCORE_KERNEL_CLASS')) {
            define('PIMCORE_KERNEL_CLASS', Kernel::class);
        }
        // in test mode Pimcore would otherwise generate classes into vendor/pimcore/pimcore/tests/_output
        if (!defined('PIMCORE_CLASS_DIRECTORY')) {
            define('PIMCORE_CLASS_DIRECTORY', $root . '/var/classes');
        }

        Bootstrap::setProjectRoot();
        Bootstrap::bootstrap();

        // Pimcore's own module keeps whatever kernel is already set, so a stub left behind by the
        // unit suite (running first, e.g. "codecept run Unit,Functional") would be used for the
        // whole functional suite. Boot the real one over it, the way that module would.
        if (Pimcore::getKernel() instanceof PimcoreStubKernel) {
            // Bootstrap::kernel() names the project's App\Kernel in its return type, a class that
            // does not exist in a standalone checkout, so narrow to the interface the code needs
            /** @var KernelInterface $kernel */
            $kernel = Bootstrap::kernel();
            $kernel->getContainer()->get('event_dispatcher')->dispatch(new GenericEvent(), TestEvents::KERNEL_BOOTED);
        }

        Autoloader::addNamespace('Pimcore\\Model\\DataObject', PIMCORE_CLASS_DIRECTORY . '/DataObject');
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function _beforeSuite(array $settings = []): void
    {
        if (!TestHelper::supportsDbTests()) {
            // Pimcore's module could not set up the database; its own TestCase skips every test
            $this->debug('[GATEKEEPER AI] No database, skipping the bundle installation and the fixtures');

            return;
        }

        $kernel = Pimcore::getKernel();
        if ($kernel === null) {
            throw new \RuntimeException('The Pimcore kernel is not booted; is the Pimcore module enabled after this one?');
        }

        foreach (['TsfGatekeeperBundle', 'TsfGatekeeperAiBundle'] as $name) {
            $installer = $kernel->getBundle($name)->getInstaller();
            if ($installer !== null && !$installer->isInstalled()) {
                $this->debug(sprintf('[GATEKEEPER AI] Installing %s', $name));
                $installer->install();
            }
        }

        ClassFixtures::create();
    }
}
