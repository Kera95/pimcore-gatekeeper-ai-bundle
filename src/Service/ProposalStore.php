<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service;

use Doctrine\DBAL\Connection;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;

use function count;

/**
 * Reads and writes tsf_gatekeeper_ai_proposal. One row per (object, field, language): a propose
 * re-run replaces pending, invalid and stale rows and leaves decided and applied ones alone.
 */
class ProposalStore
{
    public const TABLE = 'tsf_gatekeeper_ai_proposal';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public static function createTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `object_id`          INT UNSIGNED  NOT NULL,
  `class_name`         VARCHAR(190)  NOT NULL,
  `profile`            VARCHAR(100)  NOT NULL DEFAULT \'default\',
  `language`           VARCHAR(10)   NOT NULL DEFAULT \'\',
  `field_name`         VARCHAR(190)  NOT NULL,
  `current_value`      TEXT          NULL,
  `proposed_value`     MEDIUMTEXT    NOT NULL,
  `status`             VARCHAR(20)   NOT NULL,
  `invalid_reason`     VARCHAR(500)  NULL,
  `source_hash`        CHAR(64)      NOT NULL,
  `kb_hash`            CHAR(64)      NOT NULL,
  `prefix_hash`        CHAR(64)      NOT NULL,
  `prompt_version`     VARCHAR(20)   NOT NULL,
  `model`              VARCHAR(100)  NOT NULL,
  `input_tokens`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `output_tokens`      INT UNSIGNED  NOT NULL DEFAULT 0,
  `cache_read_tokens`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `cache_write_tokens` INT UNSIGNED  NOT NULL DEFAULT 0,
  `created_at`         DATETIME      NOT NULL,
  `updated_at`         DATETIME      NOT NULL,
  `applied_at`         DATETIME      NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_object_field_language` (`object_id`, `field_name`, `language`),
  KEY `idx_status_class` (`status`, `class_name`),
  KEY `idx_class_object` (`class_name`, `object_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    }

    /**
     * Inserts the proposal, or replaces the row of the same (object, field, language) when that
     * row may be replaced. Returns the row id, or null when an approved, rejected, applied or
     * gate-blocked row is in the way.
     */
    public function save(Proposal $proposal): ?int
    {
        return $this->connection->transactional(function () use ($proposal): ?int {
            $existing = $this->connection->fetchAssociative(
                'SELECT id, status FROM `' . self::TABLE . '` WHERE object_id = :object_id AND field_name = :field_name AND language = :language FOR UPDATE',
                ['object_id' => $proposal->getObjectId(), 'field_name' => $proposal->getFieldName(), 'language' => $proposal->getLanguage()]
            );

            if ($existing === false) {
                $this->connection->insert(self::TABLE, $proposal->toArray());

                return (int) $this->connection->lastInsertId();
            }

            $id = (int) $existing['id'];
            if (!ProposalStatus::from((string) $existing['status'])->isReplaceable()) {
                return null;
            }

            $this->connection->update(self::TABLE, $proposal->toArray(), ['id' => $id]);

            return $id;
        });
    }

    public function get(int $id): ?Proposal
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM `' . self::TABLE . '` WHERE id = :id', ['id' => $id]);

        return $row === false ? null : Proposal::fromArray($row);
    }

    /**
     * Proposals narrowed by any combination of class, status, object and language, ordered by
     * class, object, language and field
     *
     * @return Proposal[]
     */
    public function find(
        ?string $className = null,
        ?ProposalStatus $status = null,
        ?int $objectId = null,
        ?string $language = null,
        ?int $limit = null,
    ): array {
        $where = [];
        $params = [];
        if ($className !== null) {
            $where[] = 'class_name = :class_name';
            $params['class_name'] = $className;
        }
        if ($status !== null) {
            $where[] = 'status = :status';
            $params['status'] = $status->value;
        }
        if ($objectId !== null) {
            $where[] = 'object_id = :object_id';
            $params['object_id'] = $objectId;
        }
        if ($language !== null) {
            $where[] = 'language = :language';
            $params['language'] = $language;
        }

        $sql = 'SELECT * FROM `' . self::TABLE . '`'
            . (count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY class_name, object_id, language, field_name'
            . ($limit !== null ? ' LIMIT ' . max(0, $limit) : '');

        return array_map(
            static fn (array $row): Proposal => Proposal::fromArray($row),
            $this->connection->fetchAllAssociative($sql, $params)
        );
    }

    /**
     * @return Proposal[]
     */
    public function findByObject(int $objectId): array
    {
        return $this->find(null, null, $objectId);
    }

    /**
     * Moves a row to another status; the reason lands in invalid_reason (null clears it),
     * applied_at is stamped for applied rows only.
     */
    public function updateStatus(int $id, ProposalStatus $status, ?string $reason = null, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();

        $this->connection->update(self::TABLE, [
            'status' => $status->value,
            'invalid_reason' => $reason,
            'updated_at' => $at->format('Y-m-d H:i:s'),
            'applied_at' => $status === ProposalStatus::Applied ? $at->format('Y-m-d H:i:s') : null,
        ], ['id' => $id]);
    }

    /**
     * Row counts per status, every status present (0 when there is none)
     *
     * @return array<string, int> keyed by status value
     */
    public function countByStatus(?string $className = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS n FROM `' . self::TABLE . '`'
            . ($className !== null ? ' WHERE class_name = :class_name' : '')
            . ' GROUP BY status';
        $params = $className !== null ? ['class_name' => $className] : [];

        $counts = array_fill_keys(ProposalStatus::values(), 0);
        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Drops every proposal of the object, e.g. after the object was deleted. Returns the row count.
     */
    public function deleteByObject(int $objectId): int
    {
        return (int) $this->connection->delete(self::TABLE, ['object_id' => $objectId]);
    }
}
