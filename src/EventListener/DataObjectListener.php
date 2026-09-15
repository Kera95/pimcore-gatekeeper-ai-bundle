<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\EventListener;

use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;

use function sprintf;

/**
 * Keeps the proposal table in step with the object tree: a deleted object takes its proposals
 * with it, whatever their status. Nothing happens on save - proposals are checked against the
 * object when they are applied, not before.
 *
 * A failure here is logged, never thrown: the object is already gone when postDelete fires, and a
 * missing proposal table (bundle enabled but not installed) must not make every delete fatal.
 */
final class DataObjectListener
{
    public function __construct(
        private readonly ProposalStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onPostDelete(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        $id = $object->getId();
        if (!$object instanceof Concrete || $id === null) {
            return;
        }

        try {
            $this->store->deleteByObject($id);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Gatekeeper AI: could not delete the proposals of object #%d: %s', $id, $e->getMessage()), ['exception' => $e]);
        }
    }
}
