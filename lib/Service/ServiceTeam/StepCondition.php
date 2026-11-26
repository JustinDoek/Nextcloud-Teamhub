<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

/**
 * A condition that skips a step of a service request (v4.20.66, phase 8j;
 * `docs/service-builder.md` § 14.6, D16): one answer of the request form, an
 * operator and a value. Forward only: a condition never sends a request back.
 *
 *     when  { field, op: is | is_not | at_least | at_most | filled, value }
 *
 * Evaluated against the copy of the answers the request started with
 * (`data.form`), when the step would be reached. **A field that was not
 * answered matches nothing**, not even *is not*: a step that should only run
 * when something is *not* so must not run because nobody said.
 *
 * Which operators a field allows depends on its type (`OPS_BY_TYPE`); a long
 * text and a person can only be *filled in*.
 */
final class StepCondition {

    public const OP_IS       = 'is';
    public const OP_IS_NOT   = 'is_not';
    public const OP_AT_LEAST = 'at_least';
    public const OP_AT_MOST  = 'at_most';
    public const OP_FILLED   = 'filled';
    public const OPS         = [self::OP_IS, self::OP_IS_NOT, self::OP_AT_LEAST, self::OP_AT_MOST, self::OP_FILLED];

    public const OPS_BY_TYPE = [
        RequestForm::TYPE_SHORT  => [self::OP_IS, self::OP_IS_NOT, self::OP_FILLED],
        RequestForm::TYPE_LONG   => [self::OP_FILLED],
        RequestForm::TYPE_CHOICE => [self::OP_IS, self::OP_IS_NOT, self::OP_FILLED],
        RequestForm::TYPE_MULTI  => [self::OP_IS, self::OP_IS_NOT, self::OP_FILLED],
        RequestForm::TYPE_YESNO  => [self::OP_IS, self::OP_FILLED],
        RequestForm::TYPE_NUMBER => [self::OP_IS, self::OP_IS_NOT, self::OP_AT_LEAST, self::OP_AT_MOST, self::OP_FILLED],
        RequestForm::TYPE_DATE   => [self::OP_IS, self::OP_IS_NOT, self::OP_AT_LEAST, self::OP_AT_MOST, self::OP_FILLED],
        RequestForm::TYPE_PERSON => [self::OP_FILLED],
    ];

    public const MAX_VALUE = 255;

    /**
     * Whether a step with this condition runs. No condition: always.
     *
     * @param array<string, mixed>|null $when
     * @param array<int, mixed> $form the request's answers (`data.form`)
     */
    public static function holds(?array $when, array $form): bool {
        if ($when === null || $when === []) {
            return true;
        }
        $field = (string)($when['field'] ?? '');
        $op    = (string)($when['op'] ?? '');
        $entry = null;
        foreach ($form as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $field) {
                $entry = $candidate;
            }
        }
        if ($entry === null || !self::answered($entry['value'] ?? null)) {
            return false;
        }
        if ($op === self::OP_FILLED) {
            return true;
        }
        $type   = (string)($entry['type'] ?? '');
        $value  = $entry['value'];
        $wanted = $when['value'] ?? null;
        switch ($op) {
            case self::OP_IS:
                return self::equals($type, $value, $wanted);
            case self::OP_IS_NOT:
                return !self::equals($type, $value, $wanted);
            case self::OP_AT_LEAST:
            case self::OP_AT_MOST:
                $cmp = self::compare($type, $value, $wanted);
                return $cmp !== null && ($op === self::OP_AT_LEAST ? $cmp >= 0 : $cmp <= 0);
        }
        // An operator this version does not know runs nothing it cannot judge.
        return false;
    }

    private static function answered(mixed $value): bool {
        return $value !== null && $value !== '' && $value !== [];
    }

    private static function equals(string $type, mixed $value, mixed $wanted): bool {
        switch ($type) {
            case RequestForm::TYPE_MULTI:
                return is_array($value) && in_array((string)$wanted, array_map('strval', $value), true);
            case RequestForm::TYPE_YESNO:
                return $value === in_array($wanted, [true, 1, '1', 'true', 'yes'], true);
            case RequestForm::TYPE_NUMBER:
                return is_numeric($value) && is_numeric($wanted) && (float)$value === (float)$wanted;
            case RequestForm::TYPE_SHORT:
                return mb_strtolower(trim((string)$value)) === mb_strtolower(trim((string)$wanted));
        }
        return (string)$value === (string)$wanted;
    }

    private static function compare(string $type, mixed $value, mixed $wanted): ?int {
        if ($type === RequestForm::TYPE_NUMBER) {
            return is_numeric($value) && is_numeric($wanted) ? ((float)$value <=> (float)$wanted) : null;
        }
        if ($type === RequestForm::TYPE_DATE) {
            $ok = static fn (mixed $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
            return $ok($value) && $ok($wanted) ? strcmp($value, $wanted) <=> 0 : null;
        }
        return null;
    }
}
