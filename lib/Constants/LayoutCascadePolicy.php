<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

use OCA\TeamHub\Db\LayoutDefaultMapper;

/**
 * Picks the default home recipe without making any DB calls.
 *
 * A template provides a new team's starting layout. Once a member arranges
 * their own grid, later template edits must never reset that arrangement.
 * A team-level Enforce policy is the only source of truth above personal edits.
 * An explicit Apply once policy retains its separate rollout behavior.
 */
final class LayoutCascadePolicy {
    /**
     * @param array{layout: array, tabOrder: array, isSystemDefault: bool} $userDefault
     * @return array{source: array, updatedAt: int}
     */
    public static function resolve(?array $teamDefault, ?array $templateDefault, array $userDefault): array {
        // Explicit personal type defaults apply to EVERY unlocked team of
        // this template, including one with a non-enforced team suggestion.
        // Team-admin Enforce is handled before we reach this policy.
        // A member can opt into a default for their other teams of this same
        // template. The caller has already loaded the preference by template,
        // so unlike the old global default it cannot leak Project widgets
        // into a Collaboration team. This opt-in wins over the template recipe.
        if (!$userDefault['isSystemDefault']) {
            return ['source' => $userDefault, 'updatedAt' => 0];
        }
        if ($teamDefault !== null && ($teamDefault['mode'] ?? null) === LayoutDefaultMapper::MODE_APPLY_ONCE) {
            return ['source' => $teamDefault, 'updatedAt' => (int)($teamDefault['updatedAt'] ?? 0)];
        }
        if ($templateDefault !== null) {
            // Template layouts seed new teams until a member chooses otherwise.
            return ['source' => $templateDefault, 'updatedAt' => 0];
        }
        return ['source' => $userDefault, 'updatedAt' => 0];
    }
}
