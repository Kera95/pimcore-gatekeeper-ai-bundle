<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\EventListener;

use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;

/**
 * Keeps the proposal table in step with the object tree: a deleted object takes its proposals
 * with it, whatever their status. Nothing happens on save - proposals are checked against the
 * object when they are applied, not before.
 */
final class DataObjectListener
{
    public function __construct(
        private readonly ProposalStore $store,
    ) {
    }

    public function onPostDelete(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        if (!$object instanceof Concrete || $object->getId() === null) {
            return;
        }

        $this->store->deleteByObject($object->getId());
    }
}
