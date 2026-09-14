<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

/**
 * What a propose run did, for the console and the log: objects and requests, proposals by
 * outcome, the token usage and its price, and why the run stopped if it did not finish.
 */
final class RunSummary
{
    private int $requests = 0;

    private int $objectsSkippedFresh = 0;

    private int $objectsFailed = 0;

    private int $pending = 0;

    private int $invalid = 0;

    private int $keptDecided = 0;

    private Usage $usage;

    private float $cost = 0.0;

    private ?string $stoppedBecause = null;

    /**
     * @var string[] one line per problem, for the console
     */
    private array $problems = [];

    public function __construct()
    {
        $this->usage = Usage::none();
    }

    public function addRequest(Usage $usage, float $cost): void
    {
        $this->requests++;
        $this->usage = $this->usage->add($usage);
        $this->cost += $cost;
    }

    public function addSkippedFresh(): void
    {
        $this->objectsSkippedFresh++;
    }

    public function addFailed(string $problem): void
    {
        $this->objectsFailed++;
        $this->problems[] = $problem;
    }

    public function addPending(): void
    {
        $this->pending++;
    }

    public function addInvalid(): void
    {
        $this->invalid++;
    }

    public function addKeptDecided(): void
    {
        $this->keptDecided++;
    }

    public function stop(string $reason): void
    {
        $this->stoppedBecause = $reason;
    }

    public function getRequests(): int
    {
        return $this->requests;
    }

    public function getObjectsSkippedFresh(): int
    {
        return $this->objectsSkippedFresh;
    }

    public function getObjectsFailed(): int
    {
        return $this->objectsFailed;
    }

    public function getPending(): int
    {
        return $this->pending;
    }

    public function getInvalid(): int
    {
        return $this->invalid;
    }

    /**
     * Proposals not written because an approved, rejected or applied row was in the way
     */
    public function getKeptDecided(): int
    {
        return $this->keptDecided;
    }

    public function getUsage(): Usage
    {
        return $this->usage;
    }

    public function getCost(): float
    {
        return $this->cost;
    }

    public function getStoppedBecause(): ?string
    {
        return $this->stoppedBecause;
    }

    public function isStopped(): bool
    {
        return $this->stoppedBecause !== null;
    }

    /**
     * @return string[]
     */
    public function getProblems(): array
    {
        return $this->problems;
    }
}
