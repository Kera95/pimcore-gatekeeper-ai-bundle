<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

use function count;
use function in_array;

/**
 * The objects of one class that miss fields in one language, with the fields each of them
 * needs. One group = one cached prompt prefix; one object = one request.
 */
final class PlanGroup
{
    /**
     * @var array<int, string[]> object id => field paths, in the order the objects were reported
     */
    private array $targets = [];

    /**
     * @var array<int, array<string, string>> object id => field path => the Gatekeeper profile that reported it first
     */
    private array $profiles = [];

    /**
     * @param FieldSpec[] $specs every field this group may produce, keyed by path
     */
    public function __construct(
        private readonly string $className,
        private readonly string $language,
        private readonly array $specs,
    ) {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * "" for the group of non-localized fields
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getLabel(): string
    {
        return $this->className . ($this->language === '' ? '' : ' [' . $this->language . ']');
    }

    /**
     * @return array<string, FieldSpec> keyed by path
     */
    public function getSpecs(): array
    {
        return $this->specs;
    }

    public function addTarget(int $objectId, string $field, string $profile): void
    {
        if (!isset($this->specs[$field])) {
            return;
        }
        $this->targets[$objectId] ??= [];
        if (!in_array($field, $this->targets[$objectId], true)) {
            $this->targets[$objectId][] = $field;
            $this->profiles[$objectId][$field] = $profile;
        }
    }

    public function profileOf(int $objectId, string $field): string
    {
        return $this->profiles[$objectId][$field] ?? 'default';
    }

    /**
     * @return array<int, string[]> object id => field paths
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * @return FieldSpec[] the specs of the fields one object needs, in spec order
     */
    public function specsFor(int $objectId): array
    {
        $fields = $this->targets[$objectId] ?? [];

        return array_values(array_filter($this->specs, static fn (FieldSpec $spec): bool => in_array($spec->getPath(), $fields, true)));
    }

    public function getObjectCount(): int
    {
        return count($this->targets);
    }

    public function getFieldCount(): int
    {
        return (int) array_sum(array_map('count', $this->targets));
    }

    /**
     * Drops the objects that are not in the given id list; keeps the order
     *
     * @param int[] $objectIds
     */
    public function restrictTo(array $objectIds): void
    {
        $this->targets = array_intersect_key($this->targets, array_flip($objectIds));
        $this->profiles = array_intersect_key($this->profiles, array_flip($objectIds));
    }
}
