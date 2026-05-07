<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

/**
 * Stateful per-archive pseudonymizer.
 *
 * Replaces Nextcloud UIDs with stable positional aliases (user_0001, user_0002…)
 * within a single archive run. The alias map is held only in memory and is
 * NEVER written to the archive, so the archive cannot be de-anonymized without
 * access to the live audit log on the originating instance.
 *
 * Important scope limitations (documented in admin help text):
 *   - Only structured UID fields listed by the caller are replaced.
 *   - Message bodies, comment text, team descriptions, and web-link titles
 *     are NOT processed — they are free text that may contain names but
 *     cannot be automatically scrubbed without destroying archive value.
 *   - Group names and sub-team names are retained (organisational structure).
 *   - The archive therefore remains personal data under GDPR, but with
 *     reduced linkability. The correct term is pseudonymization, not anonymization.
 */
class ArchivePseudonymizer {

    /** @var array<string, string> uid → alias */
    private array $map = [];

    private int $counter = 0;

    /**
     * Return the stable alias for $uid, creating one if this is the first encounter.
     */
    public function aliasFor(string $uid): string {
        if ($uid === '') {
            return '';
        }
        if (!isset($this->map[$uid])) {
            $this->counter++;
            $this->map[$uid] = 'user_' . str_pad((string)$this->counter, 4, '0', STR_PAD_LEFT);
        }
        return $this->map[$uid];
    }

    /**
     * Replace the listed fields in a flat associative row with aliases.
     *
     * @param array<string, mixed> $row
     * @param string[]             $uidFields names of fields that contain UIDs
     * @return array<string, mixed>
     */
    public function process(array $row, array $uidFields): array {
        foreach ($uidFields as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = $this->aliasFor($row[$field]);
            }
        }
        return $row;
    }

    /**
     * Walk a JSON metadata blob and replace values under known UID-shaped keys.
     *
     * The known key list covers the audit-log metadata shapes used by TeamHub.
     * Unknown keys are passed through unchanged. If the JSON is malformed or
     * not an object, it is returned unchanged.
     */
    public function processMetadataJson(string $json): string {
        if ($json === '' || $json === 'null') {
            return $json;
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }
        if (!is_array($data)) {
            return $json;
        }

        $data = $this->walkMetadata($data);

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Recursively walk metadata, replacing values of known UID keys.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function walkMetadata(array $data): array {
        // Scalar UID fields found in TeamHub's audit metadata shapes.
        $uidScalarKeys = [
            'uid', 'user_id', 'actor', 'actor_uid', 'target_uid',
            'member_id', 'invited_by', 'removed_by', 'deleted_by',
            'new_owner', 'old_owner', 'author_id',
        ];
        // Array-of-UIDs fields (e.g. recipients, invitees).
        $uidArrayKeys = ['recipients', 'invitees', 'members'];

        foreach ($data as $key => $value) {
            if (in_array($key, $uidScalarKeys, true) && is_string($value) && $value !== '') {
                $data[$key] = $this->aliasFor($value);
            } elseif (in_array($key, $uidArrayKeys, true) && is_array($value)) {
                $data[$key] = array_map(
                    fn($v) => is_string($v) && $v !== '' ? $this->aliasFor($v) : $v,
                    $value
                );
            } elseif (is_array($value)) {
                $data[$key] = $this->walkMetadata($value);
            }
        }
        return $data;
    }
}
