<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

use function count;

/**
 * What a propose run would do: the groups in the order they are processed, plus what was left
 * out and why, so the user sees it before spending anything
 */
final class RunPlan
{
    /**
     * @param PlanGroup[]           $groups   non-empty groups, ordered by class then language
     * @param array<string, int>    $skipped  reason => count of (object, field) pairs left out
     */
    public function __construct(
        private readonly array $groups,
        private readonly array $skipped,
    ) {
    }

    /**
     * @return PlanGroup[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<string, int>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function isEmpty(): bool
    {
        return count($this->groups) === 0;
    }

    /**
     * Distinct objects across all groups
     */
    public function getObjectCount(): int
    {
        $ids = [];
        foreach ($this->groups as $group) {
            foreach (array_keys($group->getTargets()) as $id) {
                $ids[$id] = true;
            }
        }

        return count($ids);
    }

    /**
     * One request per (object, language)
     */
    public function getRequestCount(): int
    {
        return (int) array_sum(array_map(static fn (PlanGroup $group): int => $group->getObjectCount(), $this->groups));
    }

    public function getFieldCount(): int
    {
        return (int) array_sum(array_map(static fn (PlanGroup $group): int => $group->getFieldCount(), $this->groups));
    }
}
