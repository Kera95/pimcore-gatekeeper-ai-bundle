<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

/**
 * Outcome of checking a model's value against a FieldSpec: the normalised value to store (for a
 * multiselect a JSON array, otherwise the string) and, when it is not acceptable, why.
 */
final class ValidationResult
{
    private function __construct(
        private readonly string $value,
        private readonly ?string $reason,
    ) {
    }

    public static function valid(string $value): self
    {
        return new self($value, null);
    }

    public static function invalid(string $reason, string $value = ''): self
    {
        return new self($value, $reason);
    }

    public function isValid(): bool
    {
        return $this->reason === null;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
