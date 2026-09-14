<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Support;

use Pimcore;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Minimal kernel handed to Pimcore so that static core helpers which only need the event
 * dispatcher or a logger work in isolated unit tests, without a database, a cache or a compiled
 * container.
 */
final class PimcoreStubKernel implements KernelInterface
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
        $this->container->set('event_dispatcher', new EventDispatcher());
        // model classes resolve their logger from the container when constructed
        $this->container->set('monolog.logger.pimcore', new NullLogger());
    }

    /**
     * Installs the stub as the kernel Pimcore's static helpers read from. If the functional suite
     * ran first in the same process, the real kernel is replaced, so unit tests never silently
     * depend on a booted Pimcore.
     */
    public static function register(): void
    {
        if (!Pimcore::getKernel() instanceof self) {
            Pimcore::setKernel(new self());
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function boot(): void
    {
    }

    public function shutdown(): void
    {
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): BundleInterface
    {
        throw new \LogicException(sprintf('The stub kernel has no bundle "%s".', $name));
    }

    public function locateResource(string $name): string
    {
        throw new \LogicException(sprintf('The stub kernel cannot locate the resource "%s".', $name));
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getStartTime(): float
    {
        return 0.0;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/tsf-gatekeeper-ai-bundle-tests/cache';
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/tsf-gatekeeper-ai-bundle-tests/log';
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        throw new \LogicException('The stub kernel does not handle requests.');
    }
}
