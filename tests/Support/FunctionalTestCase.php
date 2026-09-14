<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Pimcore;
use Pimcore\Console\Application;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Tests\Support\Test\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Tests\Support\Fixture\ClassFixtures;
use Tsf\GatekeeperBundle\Service\ResultStore;

/**
 * Base of the functional tests: a booted Pimcore kernel with both bundles, tables emptied before
 * every test, the test classes from ClassFixtures and a few builders for objects, proposals and
 * commands.
 */
abstract class FunctionalTestCase extends TestCase
{
    protected function needsDb(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection()->executeStatement('DELETE FROM `' . ResultStore::TABLE . '`');
        $this->connection()->executeStatement('DELETE FROM `' . ProposalStore::TABLE . '`');
    }

    protected function connection(): Connection
    {
        return Pimcore::getContainer()->get('database_connection');
    }

    /**
     * Private services are reachable through Symfony's test container
     */
    protected function service(string $id): object
    {
        return Pimcore::getContainer()->get('test.service_container')->get($id);
    }

    /**
     * Unsaved GkProduct. Values: sku, name, weight, title / description / seo_title (arrays language => value).
     *
     * @param array<string, mixed> $values
     */
    protected function product(array $values = [], bool $published = true): Concrete
    {
        $class = 'Pimcore\\Model\\DataObject\\' . ClassFixtures::PRODUCT;
        /** @var Concrete $object */
        $object = new $class();
        $object->setParentId(1);
        $object->setUserOwner(1);
        $object->setUserModification(1);
        $object->setKey('product-' . uniqid());
        $object->setPublished($published);

        if (isset($values['sku'])) {
            $object->setSku($values['sku']);
        }
        if (isset($values['name'])) {
            $object->setName($values['name']);
        }
        if (isset($values['weight'])) {
            $object->setWeight($values['weight']);
        }
        foreach ($values['title'] ?? [] as $language => $title) {
            $object->setTitle($title, $language);
        }
        foreach ($values['description'] ?? [] as $language => $description) {
            $object->setDescription($description, $language);
        }
        foreach ($values['seo_title'] ?? [] as $language => $seoTitle) {
            $object->setSeo_title($seoTitle, $language);
        }

        return $object;
    }

    protected function category(?string $name, ?string $title = null, bool $published = true): Concrete
    {
        $class = 'Pimcore\\Model\\DataObject\\' . ClassFixtures::CATEGORY;
        /** @var Concrete $object */
        $object = new $class();
        $object->setParentId(1);
        $object->setUserOwner(1);
        $object->setUserModification(1);
        $object->setKey('category-' . uniqid());
        $object->setPublished($published);
        if ($name !== null) {
            $object->setName($name);
        }
        if ($title !== null) {
            $object->setTitle($title, 'en');
        }

        return $object;
    }

    /**
     * A pending proposal with placeholder hashes, for store tests
     */
    protected function proposal(int $objectId, string $field, string $language = '', string $value = 'Proposed', ?string $invalidReason = null): Proposal
    {
        return Proposal::create(
            $objectId,
            ClassFixtures::PRODUCT,
            'default',
            $language,
            $field,
            null,
            $value,
            hash('sha256', 'source'),
            hash('sha256', 'kb'),
            hash('sha256', 'prefix'),
            '1',
            'claude-test',
            100,
            20,
            80,
            0,
            $invalidReason,
            new \DateTimeImmutable('2026-09-14 10:00:00')
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function runCommand(string $name, array $input = []): CommandTester
    {
        // SymfonyStyle wraps blocks at the terminal width; keep messages on one line for assertions
        putenv('COLUMNS=400');
        $application = new Application(Pimcore::getKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
