<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

enum ProposalStatus: string
{
    /** generated and validated, waiting for a decision */
    case Pending = 'pending';

    /** a person approved it; the next apply run writes it */
    case Approved = 'approved';

    /** a person rejected it; a re-run leaves it alone */
    case Rejected = 'rejected';

    /** written to the object */
    case Applied = 'applied';

    /** the model's value did not fit the field definition; see invalid_reason */
    case Invalid = 'invalid';

    /** the object changed between propose and apply; not written */
    case Stale = 'stale';

    /** approved, but the Gatekeeper refused the save; see invalid_reason */
    case BlockedByGate = 'blocked_by_gate';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * Whether a propose re-run may replace a row in this status. Decisions and applied values
     * are never overwritten by the machine.
     */
    public function isReplaceable(): bool
    {
        return match ($this) {
            self::Pending, self::Invalid, self::Stale => true,
            self::Approved, self::Rejected, self::Applied, self::BlockedByGate => false,
        };
    }
}
