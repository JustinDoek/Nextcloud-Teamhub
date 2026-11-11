<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

use OCA\TeamHub\Db\LayoutDefaultMapper;

/**
 * Team dashboard layout access policy (issue #109).
 * The mode, not the presence of a suggested default, determines whether a
 * member may persist or reset a personal grid. Team admins publish an
 * enforced grid using the separately authorised dashboard-config endpoint.
 */
final class TeamLayoutPolicy {
    public static function locksPersonalGrid(?array $teamDefault): bool {
        return ($teamDefault['mode'] ?? null) === LayoutDefaultMapper::MODE_ENFORCE;
    }
}
