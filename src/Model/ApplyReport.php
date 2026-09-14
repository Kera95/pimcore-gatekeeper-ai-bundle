<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

use function count;

/**
 * What an apply run did: per proposal an outcome, per object the score before and after
 */
final class ApplyReport
{
    public const APPLIED = 'applied';

    public const WOULD_APPLY = 'would apply';

    public const STALE = 'stale';

    public const BLOCKED = 'blocked';

    public const MISSING = 'object not found';

    /**
     * @var array<int, array{proposal: Proposal, outcome: string, detail: string|null}>
     */
    private array $rows = [];

    /**
     * @var array<int, array{key: string, before: array<string, int>, after: array<string, int>}> object id => scores per "profile/language"
     */
    private array $scores = [];

    public function add(Proposal $proposal, string $outcome, ?string $detail = null): void
    {
        $this->rows[] = ['proposal' => $proposal, 'outcome' => $outcome, 'detail' => $detail];
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    public function addScores(int $objectId, string $key, array $before, array $after): void
    {
        $this->scores[$objectId] = ['key' => $key, 'before' => $before, 'after' => $after];
    }

    /**
     * @return array<int, array{proposal: Proposal, outcome: string, detail: string|null}>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, array{key: string, before: array<string, int>, after: array<string, int>}>
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->rows, static fn (array $row): bool => $row['outcome'] === $outcome));
    }

    /**
     * Distinct objects in the report, or only those with a row of the given outcome
     */
    public function getObjectCount(?string $outcome = null): int
    {
        $ids = [];
        foreach ($this->rows as $row) {
            if ($outcome === null || $row['outcome'] === $outcome) {
                $ids[$row['proposal']->getObjectId()] = true;
            }
        }

        return count($ids);
    }
}
