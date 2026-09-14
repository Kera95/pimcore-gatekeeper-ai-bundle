<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

/**
 * One row of tsf_gatekeeper_ai_proposal: a value the model proposed for one field of one object
 * in one language, with the hashes that tell whether it is still current and the token usage
 * that produced it.
 */
final class Proposal
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $objectId,
        private readonly string $className,
        private readonly string $profile,
        private readonly string $language,
        private readonly string $fieldName,
        private readonly ?string $currentValue,
        private readonly string $proposedValue,
        private readonly ProposalStatus $status,
        private readonly ?string $invalidReason,
        private readonly string $sourceHash,
        private readonly string $kbHash,
        private readonly string $prefixHash,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $inputTokens,
        private readonly int $outputTokens,
        private readonly int $cacheReadTokens,
        private readonly int $cacheWriteTokens,
        private readonly \DateTimeImmutable $createdAt,
        private readonly \DateTimeImmutable $updatedAt,
        private readonly ?\DateTimeImmutable $appliedAt,
    ) {
    }

    /**
     * A fresh proposal as the propose command produces it: no id yet, pending or invalid, timestamps now
     */
    public static function create(
        int $objectId,
        string $className,
        string $profile,
        string $language,
        string $fieldName,
        ?string $currentValue,
        string $proposedValue,
        string $sourceHash,
        string $kbHash,
        string $prefixHash,
        string $promptVersion,
        string $model,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
        ?string $invalidReason = null,
        ?\DateTimeImmutable $at = null,
    ): self {
        $at ??= new \DateTimeImmutable();

        return new self(
            null,
            $objectId,
            $className,
            $profile,
            $language,
            $fieldName,
            $currentValue,
            $proposedValue,
            $invalidReason === null ? ProposalStatus::Pending : ProposalStatus::Invalid,
            $invalidReason,
            $sourceHash,
            $kbHash,
            $prefixHash,
            $promptVersion,
            $model,
            $inputTokens,
            $outputTokens,
            $cacheReadTokens,
            $cacheWriteTokens,
            $at,
            $at,
            null
        );
    }

    /**
     * @param array<string, mixed> $row a row of the proposal table
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['object_id'],
            (string) $row['class_name'],
            (string) $row['profile'],
            (string) $row['language'],
            (string) $row['field_name'],
            $row['current_value'] === null ? null : (string) $row['current_value'],
            (string) $row['proposed_value'],
            ProposalStatus::from((string) $row['status']),
            $row['invalid_reason'] === null ? null : (string) $row['invalid_reason'],
            (string) $row['source_hash'],
            (string) $row['kb_hash'],
            (string) $row['prefix_hash'],
            (string) $row['prompt_version'],
            (string) $row['model'],
            (int) $row['input_tokens'],
            (int) $row['output_tokens'],
            (int) $row['cache_read_tokens'],
            (int) $row['cache_write_tokens'],
            new \DateTimeImmutable((string) $row['created_at']),
            new \DateTimeImmutable((string) $row['updated_at']),
            $row['applied_at'] === null ? null : new \DateTimeImmutable((string) $row['applied_at'])
        );
    }

    /**
     * @return array<string, mixed> the table columns, without id
     */
    public function toArray(): array
    {
        return [
            'object_id' => $this->objectId,
            'class_name' => $this->className,
            'profile' => $this->profile,
            'language' => $this->language,
            'field_name' => $this->fieldName,
            'current_value' => $this->currentValue,
            'proposed_value' => $this->proposedValue,
            'status' => $this->status->value,
            'invalid_reason' => $this->invalidReason,
            'source_hash' => $this->sourceHash,
            'kb_hash' => $this->kbHash,
            'prefix_hash' => $this->prefixHash,
            'prompt_version' => $this->promptVersion,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'applied_at' => $this->appliedAt?->format('Y-m-d H:i:s'),
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjectId(): int
    {
        return $this->objectId;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    /**
     * "" for fields that are not localized
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getCurrentValue(): ?string
    {
        return $this->currentValue;
    }

    public function getProposedValue(): string
    {
        return $this->proposedValue;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function getInvalidReason(): ?string
    {
        return $this->invalidReason;
    }

    public function getSourceHash(): string
    {
        return $this->sourceHash;
    }

    public function getKbHash(): string
    {
        return $this->kbHash;
    }

    public function getPrefixHash(): string
    {
        return $this->prefixHash;
    }

    public function getPromptVersion(): string
    {
        return $this->promptVersion;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->cacheReadTokens;
    }

    public function getCacheWriteTokens(): int
    {
        return $this->cacheWriteTokens;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    /**
     * "field", "field [de]" - how commands and reports name the target
     */
    public function getLabel(): string
    {
        return $this->language === '' ? $this->fieldName : $this->fieldName . ' [' . $this->language . ']';
    }
}
