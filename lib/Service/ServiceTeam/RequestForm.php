<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\Exception\ValidationException;
use OCP\IL10N;

/**
 * The request form of a service a service team built (v4.20.54, phase 8g;
 * `docs/service-builder.md` § 14.3).
 *
 * One class for its three moments, so they cannot disagree:
 *
 *   - **building** — `normalise()`, the only writer of the form's shape, and
 *     `publishProblems()`, what stands between a draft form and publishing;
 *   - **asking** — `of()`, the form a document holds, and `answers()`, which
 *     checks what a requester filled in and returns the copy the request
 *     keeps;
 *   - **reading** — `asText()`, the answers as plain text for the archive and
 *     search.
 *
 * ## The shape
 *
 *     askTeam     whether the form asks which team the request is for
 *     titleField  the key of the field whose answer names the request, or ''
 *     fields      [{key, type, label, help, required, options}]
 *
 * `key` identifies a field for good: the label may be rewritten, and what
 * refers to the field (the title, later a condition or a notice) still finds
 * it. The builder proposes one; a missing, malformed or repeated key gets a
 * new one here.
 *
 * ## The standard form
 *
 * *In one line* and *Describe what you need*, the two fields every service
 * had before. Their labels may stay empty: the form then asks in the app's
 * own words, in each requester's language, which is how every service
 * published before this class keeps reading as it did. A document without a
 * `request` **is** the standard form, team question included — whatever its
 * old `askTeam` says: that value was stored and never read, and the question
 * was always on the form.
 */
class RequestForm {

    public const TYPE_SHORT  = 'short';
    public const TYPE_LONG   = 'long';
    public const TYPE_CHOICE = 'choice';
    public const TYPE_MULTI  = 'multi';
    public const TYPE_DATE   = 'date';
    public const TYPE_NUMBER = 'number';
    public const TYPE_YESNO  = 'yesno';
    public const TYPE_PERSON = 'person';

    public const TYPES = [
        self::TYPE_SHORT, self::TYPE_LONG, self::TYPE_CHOICE, self::TYPE_MULTI,
        self::TYPE_DATE, self::TYPE_NUMBER, self::TYPE_YESNO, self::TYPE_PERSON,
    ];

    /** The types whose answer may name the request: one short line. */
    public const TITLE_TYPES = [self::TYPE_SHORT, self::TYPE_CHOICE];

    /** The two fields of the standard form; their labels may stay empty. */
    public const KEY_SUMMARY = 'summary';
    public const KEY_DETAILS = 'details';

    public const MAX_FIELDS  = 20;
    public const MAX_OPTIONS = 20;
    public const MAX_LABEL   = 100;
    public const MAX_HELP    = 255;
    public const MAX_OPTION  = 100;
    public const MAX_SHORT   = 255;
    public const MAX_LONG    = 4000;

    public function __construct(
        private IL10N                 $l,
        private RequestPersonResolver $people,
    ) {
    }

    // ── The form a document holds ───────────────────────────────────────

    /** @return array{askTeam: bool, titleField: string, fields: list<array<string, mixed>>} */
    public static function standard(): array {
        return [
            'askTeam'    => true,
            'titleField' => self::KEY_SUMMARY,
            'fields'     => [
                ['key' => self::KEY_SUMMARY, 'type' => self::TYPE_SHORT, 'label' => '', 'help' => '', 'required' => true, 'options' => []],
                ['key' => self::KEY_DETAILS, 'type' => self::TYPE_LONG,  'label' => '', 'help' => '', 'required' => true, 'options' => []],
            ],
        ];
    }

    /**
     * The form of a stored document. The document was written by
     * `normalise()`; one saved before it had a form is the standard form.
     *
     * @param array<string, mixed> $document
     * @return array{askTeam: bool, titleField: string, fields: list<array<string, mixed>>}
     */
    public static function of(array $document): array {
        $request = $document['request'] ?? null;
        if (!is_array($request)) {
            return self::standard();
        }
        return [
            'askTeam'    => (bool)($request['askTeam'] ?? true),
            'titleField' => (string)($request['titleField'] ?? ''),
            'fields'     => array_values(array_filter((array)($request['fields'] ?? []), 'is_array')),
        ];
    }

    /**
     * What a field is called: its own label, or the app's words for a
     * standard field that has none.
     *
     * @param array<string, mixed> $field
     */
    public static function labelOf(IL10N $l, array $field): string {
        $label = trim((string)($field['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        switch ((string)($field['key'] ?? '')) {
            case self::KEY_SUMMARY:
                return $l->t('In one line');
            case self::KEY_DETAILS:
                return $l->t('Describe what you need');
        }
        return '';
    }

    // ── Building ────────────────────────────────────────────────────────

    /**
     * The form as the builder sent it, in its one shape. `null` — nothing
     * sent — is the standard form.
     *
     * A draft may be unfinished (a field without a name, a choice without
     * options); it is still refused for what it *holds*: an unknown type, a
     * line break in a name, too much of anything.
     *
     * @return array{askTeam: bool, titleField: string, fields: list<array{key: string, type: string, label: string, help: string, required: bool, options: list<string>}>}
     * @throws ValidationException
     */
    public function normalise(mixed $raw): array {
        if ($raw === null) {
            return self::standard();
        }
        if (!is_array($raw)) {
            throw new ValidationException($this->l->t('The request form could not be read.'));
        }
        $rawFields = $raw['fields'] ?? [];
        if (!is_array($rawFields) || !array_is_list($rawFields)) {
            throw new ValidationException($this->l->t('The request form could not be read.'));
        }
        if (count($rawFields) > self::MAX_FIELDS) {
            throw new ValidationException($this->l->n(
                'A request form has at most %n field.',
                'A request form has at most %n fields.',
                self::MAX_FIELDS,
            ));
        }

        $fields = [];
        $taken  = [];
        foreach ($rawFields as $rawField) {
            if (!is_array($rawField)) {
                throw new ValidationException($this->l->t('The request form could not be read.'));
            }
            $type = $rawField['type'] ?? '';
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new ValidationException($this->l->t('Pick one of the field types.'));
            }
            $key = $rawField['key'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) || isset($taken[$key])) {
                do {
                    $key = 'f' . bin2hex(random_bytes(4));
                } while (isset($taken[$key]));
            }
            $taken[$key] = true;
            $fields[] = [
                'key'      => $key,
                'type'     => $type,
                // TRANSLATORS: %s is a number of characters
                'label'    => $this->line($rawField['label'] ?? '', self::MAX_LABEL, $this->l->t('The name of a field is at most %s characters.', [self::MAX_LABEL])),
                // TRANSLATORS: %s is a number of characters
                'help'     => $this->line($rawField['help'] ?? '', self::MAX_HELP, $this->l->t('The explanation of a field is at most %s characters.', [self::MAX_HELP])),
                'required' => in_array($rawField['required'] ?? false, [true, 1, '1', 'true'], true),
                'options'  => in_array($type, [self::TYPE_CHOICE, self::TYPE_MULTI], true)
                    ? $this->options($rawField['options'] ?? [])
                    : [],
            ];
        }

        $titleField = is_string($raw['titleField'] ?? null) ? $raw['titleField'] : '';
        return [
            'askTeam'    => !in_array($raw['askTeam'] ?? true, [false, 0, '0', 'false'], true),
            'titleField' => self::mayName($fields, $titleField) ? $titleField : '',
            'fields'     => $fields,
        ];
    }

    /**
     * What stands between this form and publishing, in the viewer's language.
     *
     * @param array<string, mixed> $request a normalised form
     * @return list<string>
     */
    public function publishProblems(array $request): array {
        $unnamed  = false;
        $noChoice = false;
        foreach ((array)($request['fields'] ?? []) as $field) {
            $unnamed  = $unnamed || self::labelOf($this->l, $field) === '';
            $noChoice = $noChoice
                || (in_array($field['type'] ?? '', [self::TYPE_CHOICE, self::TYPE_MULTI], true) && count((array)($field['options'] ?? [])) < 2);
        }
        $problems = [];
        if ($unnamed) {
            $problems[] = $this->l->t('Give every field of the request form a name.');
        }
        if ($noChoice) {
            $problems[] = $this->l->t('Give every field with choices at least two options.');
        }
        return $problems;
    }

    // ── Asking ──────────────────────────────────────────────────────────

    /**
     * Check what a requester filled in against the form and return the copy
     * the request keeps: one entry per answered field, with the label and
     * the type it was asked under. An answer to a field the form does not
     * have is dropped; a field nobody answered is left out.
     *
     * @param array{fields: list<array<string, mixed>>} $request
     * @param array<string, mixed> $answers field key → what was filled in
     * @return list<array{key: string, type: string, label: string, value: string|bool|list<string>, text?: string}>
     * @throws ValidationException
     */
    public function answers(array $request, array $answers): array {
        $out = [];
        foreach ($request['fields'] as $field) {
            $key   = (string)($field['key'] ?? '');
            $type  = (string)($field['type'] ?? '');
            $entry = $this->answer($field, $answers[$key] ?? null);
            if ($entry === null) {
                if (!empty($field['required'])) {
                    // TRANSLATORS: %s is the name of a field on a request form
                    throw new ValidationException($this->l->t('Fill in "%s".', [self::labelOf($this->l, $field)]));
                }
                continue;
            }
            $out[] = ['key' => $key, 'type' => $type, 'label' => (string)($field['label'] ?? '')] + $entry;
        }
        return $out;
    }

    /** Said when the service was published again while somebody filled in its form. */
    public function changedMessage(): string {
        return $this->l->t('This service has changed. Check your answers and send the request again.');
    }

    public function unreadableMessage(): string {
        return $this->l->t('The answers could not be read.');
    }

    /**
     * The answer that names the request, or ''.
     *
     * @param list<array<string, mixed>> $form what `answers()` returned
     */
    public static function titleOf(array $form, string $titleField): string {
        foreach ($form as $entry) {
            if (($entry['key'] ?? '') === $titleField && is_string($entry['value'] ?? null)) {
                return mb_substr($entry['value'], 0, self::MAX_SHORT);
            }
        }
        return '';
    }

    // ── Reading ─────────────────────────────────────────────────────────

    /**
     * The answers as plain text, one per line, without the one the request
     * is named after: its title already says it.
     *
     * An answer whose field has no label of its own (a standard field) is
     * printed bare, which is what *Describe what you need* always looked
     * like on a request.
     *
     * @param array<int, mixed> $form what `answers()` returned
     */
    public static function asText(IL10N $l, array $form, string $titleField): string {
        $lines = [];
        foreach ($form as $entry) {
            if (!is_array($entry) || ($entry['key'] ?? '') === $titleField) {
                continue;
            }
            $text = self::valueText($l, $entry);
            if ($text === '') {
                continue;
            }
            $label   = trim((string)($entry['label'] ?? ''));
            $lines[] = $label === ''
                ? $text
                // TRANSLATORS: %1$s is the name of a field on a request form, %2$s what was filled in
                : $l->t('%1$s: %2$s', [$label, $text]);
        }
        return implode("\n", $lines);
    }

    /**
     * The answers a notice carries (v4.20.62), in the form's order: the name
     * of each field and what was filled in. One that was not answered is left
     * out.
     *
     * @param array<int, mixed> $form what `answers()` returned
     * @param string[] $keys the fields that were ticked
     * @return list<array{0: string, 1: string}>
     */
    public static function answersFor(IL10N $l, array $form, array $keys): array {
        $out = [];
        foreach ($form as $entry) {
            if (!is_array($entry) || !in_array((string)($entry['key'] ?? ''), $keys, true)) {
                continue;
            }
            $text = self::valueText($l, $entry);
            if ($text !== '') {
                $out[] = [self::labelOf($l, $entry), $text];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $entry */
    private static function valueText(IL10N $l, array $entry): string {
        $value = $entry['value'] ?? '';
        switch ((string)($entry['type'] ?? '')) {
            case self::TYPE_MULTI:
                return is_array($value) ? implode(', ', array_map('strval', $value)) : '';
            case self::TYPE_YESNO:
                return $value === true ? $l->t('Yes') : $l->t('No');
            case self::TYPE_PERSON:
                return (string)($entry['text'] ?? $value);
            case self::TYPE_DATE:
                try {
                    // At noon, so no time zone moves it a day.
                    return (string)$l->l('date', new \DateTime((string)$value . ' 12:00:00'));
                } catch (\Throwable) {
                    return (string)$value;
                }
        }
        return is_string($value) ? $value : '';
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $fields */
    private static function mayName(array $fields, string $key): bool {
        foreach ($fields as $field) {
            if ($field['key'] === $key) {
                return in_array($field['type'], self::TITLE_TYPES, true);
            }
        }
        return false;
    }

    /**
     * One answer, checked; null when the field was not answered.
     *
     * @param array<string, mixed> $field
     * @return array{value: string|bool|list<string>, text?: string}|null
     * @throws ValidationException
     */
    private function answer(array $field, mixed $raw): ?array {
        $label   = self::labelOf($this->l, $field);
        $options = array_map('strval', (array)($field['options'] ?? []));
        $type    = (string)($field['type'] ?? '');

        if ($type === self::TYPE_MULTI) {
            if ($raw === null || $raw === '' || $raw === []) {
                return null;
            }
            if (!is_array($raw)) {
                throw $this->notReadable($label);
            }
            $picked = [];
            foreach ($raw as $one) {
                if (!is_string($one) || !in_array($one, $options, true)) {
                    throw $this->notAnOption($label);
                }
                $picked[$one] = true;
            }
            // In the form's order, whatever order they were ticked in.
            return ['value' => array_values(array_filter($options, static fn (string $o): bool => isset($picked[$o])))];
        }

        if ($type === self::TYPE_YESNO) {
            if ($raw === null || $raw === '') {
                return null;
            }
            if (!is_bool($raw)) {
                throw $this->notReadable($label);
            }
            return ['value' => $raw];
        }

        if ($raw !== null && !is_string($raw) && !is_int($raw) && !is_float($raw)) {
            throw $this->notReadable($label);
        }
        $value = trim(str_replace("\r\n", "\n", (string)$raw));
        if ($value === '') {
            return null;
        }
        $control = $type === self::TYPE_LONG ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        if (preg_match($control, $value) !== 0) {
            // TRANSLATORS: %s is the name of a field on a request form
            throw new ValidationException($this->l->t('"%s" contains characters that are not allowed.', [$label]));
        }
        $max = $type === self::TYPE_LONG ? self::MAX_LONG : self::MAX_SHORT;
        if (mb_strlen($value) > $max) {
            // TRANSLATORS: %1$s is the name of a field on a request form, %2$s a number of characters
            throw new ValidationException($this->l->t('"%1$s" is at most %2$s characters.', [$label, $max]));
        }

        switch ($type) {
            case self::TYPE_SHORT:
            case self::TYPE_LONG:
                return ['value' => $value];

            case self::TYPE_CHOICE:
                if (!in_array($value, $options, true)) {
                    throw $this->notAnOption($label);
                }
                return ['value' => $value];

            case self::TYPE_DATE:
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    // TRANSLATORS: %s is the name of a field on a request form
                    throw new ValidationException($this->l->t('"%s" is not a date.', [$label]));
                }
                return ['value' => $value];

            case self::TYPE_NUMBER:
                // A comma is how half of our users type a decimal point.
                $number = str_replace(',', '.', $value);
                if (!preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $number)) {
                    // TRANSLATORS: %s is the name of a field on a request form
                    throw new ValidationException($this->l->t('"%s" is not a number.', [$label]));
                }
                return ['value' => $number];

            case self::TYPE_PERSON:
                $name = $this->people->nameFor($value);
                if ($name === null) {
                    // TRANSLATORS: %s is the name of a field on a request form
                    throw new ValidationException($this->l->t('Pick a person for "%s".', [$label]));
                }
                return ['value' => $value, 'text' => $name];
        }
        throw $this->notReadable($label);
    }

    /**
     * The options of a choice: trimmed, without the empty and the repeated.
     *
     * @return list<string>
     * @throws ValidationException
     */
    private function options(mixed $raw): array {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ValidationException($this->l->t('The request form could not be read.'));
        }
        $out = [];
        foreach ($raw as $option) {
            // TRANSLATORS: %s is a number of characters
            $option = $this->line($option, self::MAX_OPTION, $this->l->t('An option is at most %s characters.', [self::MAX_OPTION]));
            if ($option !== '' && !in_array($option, $out, true)) {
                $out[] = $option;
            }
        }
        if (count($out) > self::MAX_OPTIONS) {
            throw new ValidationException($this->l->n(
                'A field has at most %n option.',
                'A field has at most %n options.',
                self::MAX_OPTIONS,
            ));
        }
        return $out;
    }

    private function line(mixed $value, int $max, string $tooLong): string {
        if (!is_string($value) && !is_int($value)) {
            throw new ValidationException($this->l->t('The request form could not be read.'));
        }
        $value = trim((string)$value);
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) !== 0) {
            throw new ValidationException($this->l->t('Names and options cannot contain line breaks or special characters.'));
        }
        if (mb_strlen($value) > $max) {
            throw new ValidationException($tooLong);
        }
        return $value;
    }

    private function notReadable(string $label): ValidationException {
        // TRANSLATORS: %s is the name of a field on a request form
        return new ValidationException($this->l->t('The answer to "%s" could not be read.', [$label]));
    }

    private function notAnOption(string $label): ValidationException {
        // TRANSLATORS: %s is the name of a field on a request form
        return new ValidationException($this->l->t('Pick one of the options for "%s".', [$label]));
    }
}
