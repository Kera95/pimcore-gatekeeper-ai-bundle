<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

/**
 * What came back: the decoded values keyed by field path (empty on refusal or truncation),
 * the usage to bill, and enough of the envelope to explain a bad outcome.
 */
final class EnrichmentResponse
{
    public const STOP_END_TURN = 'end_turn';

    public const STOP_MAX_TOKENS = 'max_tokens';

    public const STOP_REFUSAL = 'refusal';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly array $values,
        private readonly Usage $usage,
        private readonly string $model,
        private readonly string $stopReason,
        private readonly ?string $requestId = null,
        private readonly ?string $refusalCategory = null,
    ) {
    }

    /**
     * @return array<string, mixed> field path => value as the model returned it
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getUsage(): Usage
    {
        return $this->usage;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getStopReason(): string
    {
        return $this->stopReason;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function isRefused(): bool
    {
        return $this->stopReason === self::STOP_REFUSAL;
    }

    public function getRefusalCategory(): ?string
    {
        return $this->refusalCategory;
    }

    public function isTruncated(): bool
    {
        return $this->stopReason === self::STOP_MAX_TOKENS;
    }

    /**
     * Whether the values can be used: the model finished and did not refuse
     */
    public function isUsable(): bool
    {
        return $this->stopReason === self::STOP_END_TURN;
    }

    /**
     * Why the response cannot be used, for the log and the run summary
     */
    public function describeProblem(): ?string
    {
        return match ($this->stopReason) {
            self::STOP_END_TURN => null,
            self::STOP_REFUSAL => 'the model refused' . ($this->refusalCategory !== null ? ' (' . $this->refusalCategory . ')' : ''),
            self::STOP_MAX_TOKENS => 'the answer was cut off at max_tokens; raise anthropic.max_tokens or request fewer fields',
            default => 'unexpected stop reason "' . $this->stopReason . '"',
        };
    }
}
