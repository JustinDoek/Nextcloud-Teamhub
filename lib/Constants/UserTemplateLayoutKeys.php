<?php
declare(strict_types=1);

namespace OCA\TeamHub\Constants;

/**
 * Preference names for a member's dashboard default on one team template.
 * Kept separate from the legacy global `default_layout_json` preference:
 * automatically migrating a Project layout into every type would carry
 * Project-only widget positions into Collaboration or Service teams.
 */
final class UserTemplateLayoutKeys {
    public static function layout(string $templateKey): string {
        self::validate($templateKey);
        return 'default_layout_template_' . $templateKey;
    }

    public static function tabOrder(string $templateKey): string {
        self::validate($templateKey);
        return 'default_tab_order_template_' . $templateKey;
    }

    private static function validate(string $key): void {
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid template key for a personal layout');
        }
    }
}
