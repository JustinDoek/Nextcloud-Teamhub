<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\MyWork\Priority;

/**
 * OpenProject's own vocabulary, read once and kept (v4.9.7, Phase 3).
 *
 * Three things the aggregation needs that a work-package row does not
 * carry: whether a status counts as *closed* (the row names the status, not
 * its kind), how a priority ranks (the row names it, and names are
 * configured per instance — "Hoch" on one, "High" on another), and which
 * types are milestones. All three are administrator configuration in
 * OpenProject and change rarely, so each is read as the viewer once and
 * cached for `OpenProjectCache::TTL_TYPES` per user and host.
 *
 * ## Priority without guessing at names
 *
 * OpenProject orders priorities by `position` (low first) and marks one
 * `isDefault`. Anything above the default is HIGH, below it LOW, the
 * default and anything unknown NORMAL. That is instance-independent and
 * language-independent, and it never labels a work package more urgent
 * than the OpenProject administrator did. The source label still travels
 * on the row (`metadata.openProjectPriority`) so nothing is hidden behind
 * the bucket.
 *
 * Every lookup degrades to "unknown" rather than throwing: a viewer who may
 * see work packages but not the priority list (OpenProject's permission is
 * per project) still gets their rows, at NORMAL.
 */
class OpenProjectReferenceService {

    public function __construct(
        private OpenProjectClient $client,
        private OpenProjectCache  $cache,
    ) {
    }

    /**
     * Status id → is it closed. Empty when the list cannot be read.
     *
     * @return array<int, bool>
     */
    public function closedStatuses(string $userId): array {
        $entries = $this->collection($userId, 'statuses', 'Status');
        $out     = [];
        foreach ($entries as $status) {
            $out[(int)$status['id']] = (bool)($status['isClosed'] ?? false);
        }
        return $out;
    }

    /**
     * Priority id → My Work bucket (`Priority::HIGH|NORMAL|LOW`). Empty when
     * the list cannot be read, in which case every row is NORMAL.
     *
     * @return array<int, string>
     */
    public function priorityRanks(string $userId): array {
        $entries = $this->collection($userId, 'priorities', 'Priority');
        if ($entries === []) {
            return [];
        }
        $default = null;
        foreach ($entries as $p) {
            if (($p['isDefault'] ?? false) === true && isset($p['position']) && is_numeric($p['position'])) {
                $default = (int)$p['position'];
                break;
            }
        }
        $out = [];
        foreach ($entries as $p) {
            $pos = isset($p['position']) && is_numeric($p['position']) ? (int)$p['position'] : null;
            $out[(int)$p['id']] = match (true) {
                $default === null || $pos === null => Priority::NORMAL,
                $pos > $default                    => Priority::HIGH,
                $pos < $default                    => Priority::LOW,
                default                            => Priority::NORMAL,
            };
        }
        return $out;
    }

    /**
     * Ids of the project's milestone types, as the viewer. The same read
     * `OpenProjectProjectService::milestoneTypeIds()` makes for the overview,
     * under the same cache key, so the widget and the queue share one
     * request.
     *
     * @return list<int>
     * @throws OpenProjectException
     */
    public function milestoneTypeIds(string $userId, int $projectId): array {
        $host = $this->client->getHost();
        $key  = $this->cache->key('types', $userId, '-', $projectId, $host);
        $ids  = $this->cache->get($key);
        if (is_array($ids)) {
            return array_map('intval', $ids);
        }

        $raw        = $this->client->get($userId, 'projects/' . $projectId . '/types');
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'projects/{id}/types did not return a collection');
        }
        $ids = [];
        foreach ($collection['elements'] as $type) {
            if (($type['isMilestone'] ?? false) === true && isset($type['id']) && is_numeric($type['id'])) {
                $ids[] = (int)$type['id'];
            }
        }
        $this->cache->set($key, $ids, OpenProjectCache::TTL_TYPES);
        return $ids;
    }

    /**
     * One instance-wide list as the viewer, cached. Never throws: an
     * unreadable list is an empty one, and the caller falls back to
     * "unknown" for every id.
     *
     * @return list<array<string, mixed>>
     */
    private function collection(string $userId, string $endpoint, string $type): array {
        $host = $this->client->getHost();
        if ($host === '') {
            return [];
        }
        $key    = $this->cache->userKey('ref:' . $endpoint, $userId, $host);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $raw        = $this->client->get($userId, $endpoint, ['pageSize' => 100]);
            $collection = OpenProjectNormalizer::collection($raw);
        } catch (OpenProjectException) {
            return [];
        }
        if ($collection === null) {
            return [];
        }
        $out = [];
        foreach ($collection['elements'] as $element) {
            if (($element['_type'] ?? null) !== $type || !isset($element['id']) || !is_numeric($element['id'])) {
                continue;
            }
            // Only the fields the lookups read — the cache holds no names.
            $out[] = [
                'id'        => (int)$element['id'],
                'isClosed'  => $element['isClosed'] ?? null,
                'isDefault' => $element['isDefault'] ?? null,
                'position'  => $element['position'] ?? null,
            ];
        }
        $this->cache->set($key, $out, OpenProjectCache::TTL_TYPES);
        return $out;
    }
}
