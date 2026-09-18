<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\OpenProject;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\OpenProjectException;
use OCA\TeamHub\Exception\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Everything Phase 2 asks OpenProject for beyond Phase 1's reads (v4.9.6):
 * the templates a user may copy, parent candidates, identifier checks,
 * creating or copying a project, the copy job's status, roles, memberships
 * and a project's storages.
 *
 * Every call runs **as the given user** through {@see OpenProjectClient} —
 * there is still no service account, so a creator can only ever do in
 * OpenProject what OpenProject lets them do. A 403 here is OpenProject's
 * answer and is reported as such, never worked around.
 *
 * ## Endpoints and filters (verified against OpenProject 17's shipped API
 * spec and source, 2026-09-13)
 *
 * | Need | Endpoint |
 * |---|---|
 * | May create projects | `POST projects/form` — 200 means the form is offered, 403 means not |
 * | Templates I may copy | `projects` with `templated = t`, `active = t`, `user_action = projects/copy` |
 * | Parent candidates | `projects/available_parent_projects` (`workspace_type = project`), optionally `name_and_identifier ~ q` |
 * | Identifier free | `projects/{identifier}` — 404 means free |
 * | Create | `POST projects` |
 * | Copy a template | `POST projects/{id}/copy` → 302 → `job_statuses/{uuid}` (followed by the HTTP client) |
 * | Job status | `job_statuses/{uuid}` — `in_queue`, `in_process`, `success`, `error`, `failure`; `payload._links.project` on success |
 * | Roles | `roles` with `unit = project`, `grantable = t` |
 * | Memberships | `memberships` with `project = id`; `POST memberships`; `DELETE memberships/{id}` |
 * | Find a user | `users` with `login = x` (needs `manage_user`), else `principals` with `any_name_attribute ~ x`, `type = User` |
 * | Storages | `project_storages` with `projectId = id` |
 *
 * The copy body's `_meta` switches are `copy<Dependency>` per OpenProject's
 * `Projects::CopyService.copyable_dependencies` (members, versions,
 * categories, workPackages, workPackageAttachments, wiki, wikiPageAttachments,
 * forums, queries, boards, overview, phases, storages, storageProjectFolders,
 * fileLinks, workPackageShares) plus `sendNotifications`.
 *
 * There is **no PATCH** through the official app's `request()` (it knows GET,
 * POST, PUT and DELETE), so a membership's roles cannot be changed from
 * here: TeamHub adds and removes memberships and reports role drift.
 */
class OpenProjectProvisioningService {

    public const TEMPLATE_LIMIT = 100;
    public const PARENT_LIMIT   = 25;
    public const ROLE_LIMIT     = 100;
    public const MEMBER_LIMIT   = 500;

    /** Cache of the "may create projects" probe, per user. */
    private const TTL_CAN_CREATE = 60;

    public function __construct(
        private OpenProjectClient $client,
        private OpenProjectCache  $cache,
        private LoggerInterface   $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Capabilities
    // ─────────────────────────────────────────────────────────────────────

    /**
     * May this user create a project at all. OpenProject's own answer: the
     * project creation form is offered (200) or refused (403). Cached
     * briefly per user; every other failure is reported, not guessed.
     *
     * @throws OpenProjectException for anything but a 403
     */
    public function canCreateProjects(string $userId, bool $force = false): bool {
        $key = $this->cache->userKey('can_create_projects', $userId, $this->client->getHost());
        if (!$force) {
            $cached = $this->cache->get($key);
            if (is_bool($cached)) {
                return $cached;
            }
        }
        try {
            $form = $this->client->post($userId, 'projects/form', []);
            $can  = ($form['_type'] ?? null) === 'Form';
        } catch (OpenProjectException $e) {
            if ($e->getErrorCode() !== OpenProjectException::PERMISSION_DENIED) {
                throw $e;
            }
            $can = false;
        }
        $this->cache->set($key, $can, self::TTL_CAN_CREATE);
        return $can;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Templates and parents
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The OpenProject project templates this user may copy — projects
     * flagged as template that OpenProject says the user has the `copy
     * projects` action in. Only what the user can use is listed; an
     * administrator's approved list narrows it further in the caller.
     *
     * @return list<array{id:int, identifier:string, name:string, active:bool, public:bool, canEditProject:bool, linkable:bool}>
     * @throws OpenProjectException
     */
    public function listTemplates(string $userId): array {
        $raw = $this->client->get($userId, 'projects', [
            'filters'  => json_encode([
                ['templated'   => ['operator' => '=', 'values' => ['t']]],
                ['active'      => ['operator' => '=', 'values' => ['t']]],
                ['user_action' => ['operator' => '=', 'values' => ['projects/copy']]],
            ], JSON_THROW_ON_ERROR),
            'sortBy'   => json_encode([['name', 'asc']], JSON_THROW_ON_ERROR),
            'pageSize' => self::TEMPLATE_LIMIT,
        ]);
        return $this->projectSummaries($raw);
    }

    /**
     * Projects this user may create a new project under.
     *
     * @return list<array{id:int, identifier:string, name:string, active:bool, public:bool, canEditProject:bool, linkable:bool}>
     * @throws OpenProjectException
     */
    public function listParentCandidates(string $userId, string $query = ''): array {
        $query = trim($query);
        if (mb_strlen($query) > 200) {
            throw new ValidationException('Search query too long');
        }
        $params = [
            'workspace_type' => 'project',
            'sortBy'         => json_encode([['name', 'asc']], JSON_THROW_ON_ERROR),
            'pageSize'       => self::PARENT_LIMIT,
        ];
        if ($query !== '') {
            $params['filters'] = json_encode([
                ['name_and_identifier' => ['operator' => '~', 'values' => [$query]]],
            ], JSON_THROW_ON_ERROR);
        }
        $raw = $this->client->get($userId, 'projects/available_parent_projects', $params);
        return $this->projectSummaries($raw);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Identifiers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * OpenProject's identifier rules, applied before OpenProject is asked:
     * lowercase letters, digits, dashes and underscores, starting with a
     * letter or digit, 1–100 characters. The same character class Phase 1's
     * normaliser accepts, so an identifier that passes here never fails a
     * URL later.
     */
    public static function isValidIdentifier(string $identifier): bool {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $identifier) === 1;
    }

    /** A candidate identifier from a project name, in OpenProject's own character class. */
    public static function suggestIdentifier(string $name): string {
        $slug = mb_strtolower(trim($name));
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
            if (is_string($ascii) && $ascii !== '') {
                $slug = strtolower($ascii);
            }
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        if ($slug === '' || !preg_match('/^[a-z0-9]/', $slug)) {
            $slug = 'project' . ($slug === '' ? '' : '-' . $slug);
        }
        return mb_substr($slug, 0, 100);
    }

    /**
     * Is this identifier still free in OpenProject. A 404 means free; a
     * project the user cannot see (403) is a project all the same, so it is
     * taken; anything visible is taken.
     *
     * @throws OpenProjectException for environment failures only
     */
    public function isIdentifierAvailable(string $userId, string $identifier): bool {
        if (!self::isValidIdentifier($identifier)) {
            return false;
        }
        try {
            $this->client->get($userId, 'projects/' . rawurlencode($identifier));
            return false;
        } catch (OpenProjectException $e) {
            return match ($e->getErrorCode()) {
                OpenProjectException::PROJECT_NOT_FOUND => true,
                OpenProjectException::PERMISSION_DENIED => false,
                default => throw $e,
            };
        }
    }

    /**
     * The project with this identifier, normalised, or null when there is
     * none the user can see.
     *
     * @return ?array<string,mixed>
     * @throws OpenProjectException
     */
    public function findProjectByIdentifier(string $userId, string $identifier): ?array {
        if (!self::isValidIdentifier($identifier)) {
            return null;
        }
        try {
            $raw = $this->client->get($userId, 'projects/' . rawurlencode($identifier));
        } catch (OpenProjectException $e) {
            if ($e->getErrorCode() === OpenProjectException::PROJECT_NOT_FOUND) {
                return null;
            }
            throw $e;
        }
        if (($raw['_type'] ?? null) !== 'Project') {
            return null;
        }
        return OpenProjectNormalizer::project($raw);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Create and copy
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create a project from nothing (no template). Synchronous in
     * OpenProject.
     *
     * @return array<string,mixed> the new project, normalised
     * @throws OpenProjectException `validation_failed` carries OpenProject's sentence
     */
    public function createProject(
        string  $userId,
        string  $name,
        string  $identifier,
        string  $description = '',
        bool    $public = false,
        ?int    $parentId = null,
    ): array {
        $body = $this->projectBody($name, $identifier, $description, $public, $parentId);
        $raw  = $this->client->post($userId, 'projects', $body);
        if (($raw['_type'] ?? null) !== 'Project' || (int)($raw['id'] ?? 0) <= 0) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'POST projects did not return a Project');
        }
        return OpenProjectNormalizer::project($raw);
    }

    /**
     * Copy a template project into a new one. OpenProject runs the copy as a
     * background job and answers with its status; the caller polls
     * {@see jobStatus()} until it is `success` and reads the project from
     * its payload.
     *
     * `$copyOptions` are the blueprint's switches (`members` → `copyMembers`,
     * …). Notifications are never sent: memberships are TeamHub's step.
     *
     * @param array<string,bool> $copyOptions
     * @return array{jobId: string, status: string, message: ?string, projectId: ?int}
     * @throws OpenProjectException
     */
    public function copyProject(
        string  $userId,
        int     $templateProjectId,
        string  $name,
        string  $identifier,
        string  $description = '',
        bool    $public = false,
        ?int    $parentId = null,
        array   $copyOptions = [],
    ): array {
        if ($templateProjectId <= 0) {
            throw new ValidationException('Invalid template project id');
        }
        $body = $this->projectBody($name, $identifier, $description, $public, $parentId);
        $meta = ['sendNotifications' => false];
        foreach ($copyOptions as $key => $enabled) {
            $meta['copy' . ucfirst((string)$key)] = (bool)$enabled;
        }
        $body['_meta'] = $meta;

        $raw = $this->client->post($userId, 'projects/' . $templateProjectId . '/copy', $body);
        return $this->normaliseJobStatus($raw);
    }

    /**
     * The status of an OpenProject background job.
     *
     * @return array{jobId: string, status: string, message: ?string, projectId: ?int}
     * @throws OpenProjectException
     */
    public function jobStatus(string $userId, string $jobId): array {
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $jobId)) {
            throw new ValidationException('Invalid job id');
        }
        $raw = $this->client->get($userId, 'job_statuses/' . $jobId);
        return $this->normaliseJobStatus($raw);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Roles and memberships
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The project roles a membership may be given.
     *
     * @return list<array{id:int, name:string}>
     * @throws OpenProjectException
     */
    public function listRoles(string $userId): array {
        $raw = $this->client->get($userId, 'roles', [
            'filters'  => json_encode([
                ['unit'      => ['operator' => '=', 'values' => ['project']]],
                ['grantable' => ['operator' => '=', 'values' => ['t']]],
            ], JSON_THROW_ON_ERROR),
            'pageSize' => self::ROLE_LIMIT,
        ]);
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'roles did not return a collection');
        }
        $out = [];
        foreach ($collection['elements'] as $role) {
            if (($role['_type'] ?? null) !== 'Role') {
                continue;
            }
            $id = (int)($role['id'] ?? 0);
            if ($id > 0) {
                $out[] = ['id' => $id, 'name' => OpenProjectNormalizer::text((string)($role['name'] ?? ''))];
            }
        }
        return $out;
    }

    /**
     * The memberships of a project as the user sees them.
     *
     * @return list<array{id:int, principalId:?int, principalType:string, principalName:string, roles:list<array{id:int, name:string}>}>
     * @throws OpenProjectException
     */
    public function listMemberships(string $userId, int $projectId): array {
        $raw = $this->client->get($userId, 'memberships', [
            'filters'  => json_encode([
                ['project' => ['operator' => '=', 'values' => [(string)$projectId]]],
            ], JSON_THROW_ON_ERROR),
            'pageSize' => self::MEMBER_LIMIT,
        ]);
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'memberships did not return a collection');
        }
        $out = [];
        foreach ($collection['elements'] as $m) {
            if (($m['_type'] ?? null) !== 'Membership') {
                continue;
            }
            $out[] = $this->normaliseMembership($m);
        }
        return $out;
    }

    /**
     * Give a principal roles in a project. OpenProject enforces the caller's
     * `manage_members` permission and the roles' grantability; a refusal is
     * its 403 or 422, reported as such.
     *
     * @param list<int> $roleIds
     * @return array{id:int, principalId:?int, principalType:string, principalName:string, roles:list<array{id:int, name:string}>}
     * @throws OpenProjectException
     */
    public function createMembership(string $userId, int $projectId, int $principalId, array $roleIds, string $principalType = 'users'): array {
        if ($projectId <= 0 || $principalId <= 0 || $roleIds === []) {
            throw new ValidationException('A membership needs a project, a principal and at least one role');
        }
        if (!in_array($principalType, ['users', 'groups'], true)) {
            throw new ValidationException('Invalid principal type');
        }
        $body = [
            '_links' => [
                'principal' => ['href' => '/api/v3/' . $principalType . '/' . $principalId],
                'project'   => ['href' => '/api/v3/projects/' . $projectId],
                'roles'     => array_map(static fn (int $id): array => ['href' => '/api/v3/roles/' . $id], array_values(array_unique($roleIds))),
            ],
            // OpenProject's representer reads `sendNotifications`; its API
            // documentation example spells it `sendNotification`. Both are
            // sent — an unknown `_meta` key is ignored, and either spelling
            // means the same thing: TeamHub does its own telling.
            '_meta'  => ['sendNotifications' => false, 'sendNotification' => false],
        ];
        $raw = $this->client->post($userId, 'memberships', $body);
        if (($raw['_type'] ?? null) !== 'Membership') {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'POST memberships did not return a Membership');
        }
        return $this->normaliseMembership($raw);
    }

    /** @throws OpenProjectException */
    public function deleteMembership(string $userId, int $membershipId): void {
        if ($membershipId <= 0) {
            throw new ValidationException('Invalid membership id');
        }
        $this->client->delete($userId, 'memberships/' . $membershipId);
    }

    /**
     * Find the OpenProject user for a Nextcloud account, by login and email.
     * Tries the users list (exact login; needs `manage_user` in OpenProject)
     * and falls back to the principals the caller can see (`any_name_attribute`,
     * then an exact login or email match on the result). Null when nothing
     * matches exactly — a near miss is never a match.
     *
     * @return ?array{id:int, login:string, name:string, email:string}
     * @throws OpenProjectException for environment failures only
     */
    public function findUser(string $userId, string $login, string $email = ''): ?array {
        $login = trim($login);
        $email = trim($email);
        if ($login === '' && $email === '') {
            return null;
        }

        // 1. Exact login on the users list — the authoritative answer, for
        //    a caller OpenProject lets manage users.
        if ($login !== '') {
            try {
                $raw = $this->client->get($userId, 'users', [
                    'filters'  => json_encode([['login' => ['operator' => '=', 'values' => [$login]]]], JSON_THROW_ON_ERROR),
                    'pageSize' => 5,
                ]);
                $hit = $this->exactUser($raw, $login, $email);
                if ($hit !== null) {
                    return $hit;
                }
            } catch (OpenProjectException $e) {
                if ($e->getErrorCode() !== OpenProjectException::PERMISSION_DENIED
                    && $e->getErrorCode() !== OpenProjectException::PROJECT_NOT_FOUND) {
                    throw $e;
                }
            }
        }

        // 2. Principals visible to the caller, by any name attribute.
        foreach (array_values(array_unique(array_filter([$email, $login]))) as $needle) {
            $raw = $this->client->get($userId, 'principals', [
                'filters'  => json_encode([
                    ['any_name_attribute' => ['operator' => '~', 'values' => [$needle]]],
                    ['type'               => ['operator' => '=', 'values' => ['User']]],
                ], JSON_THROW_ON_ERROR),
                'pageSize' => 25,
            ]);
            $hit = $this->exactUser($raw, $login, $email);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * Find the OpenProject group for a Nextcloud group, by exact name (the
     * group's display name, then its id). Groups are principals the caller
     * can see; a near miss is never a match.
     *
     * @return ?array{id:int, name:string}
     * @throws OpenProjectException for environment failures only
     */
    public function findGroup(string $userId, string $displayName, string $gid = ''): ?array {
        foreach (array_values(array_unique(array_filter([trim($displayName), trim($gid)]))) as $needle) {
            $raw = $this->client->get($userId, 'principals', [
                'filters'  => json_encode([
                    ['any_name_attribute' => ['operator' => '~', 'values' => [$needle]]],
                    ['type'               => ['operator' => '=', 'values' => ['Group']]],
                ], JSON_THROW_ON_ERROR),
                'pageSize' => 25,
            ]);
            $collection = OpenProjectNormalizer::collection($raw);
            if ($collection === null) {
                continue;
            }
            foreach ($collection['elements'] as $g) {
                if (($g['_type'] ?? null) !== 'Group') {
                    continue;
                }
                $name = (string)($g['name'] ?? '');
                if (strcasecmp($name, $needle) === 0 && (int)($g['id'] ?? 0) > 0) {
                    return ['id' => (int)$g['id'], 'name' => OpenProjectNormalizer::text($name)];
                }
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Storages
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The storages linked to a project, with the managed folder's file id
     * when OpenProject manages one.
     *
     * @return list<array{id:int, storageId:?int, storageName:?string, projectFolderMode:string, projectFolderFileId:?int, openUrl:?string}>
     * @throws OpenProjectException
     */
    public function projectStorages(string $userId, int $projectId): array {
        $raw = $this->client->get($userId, 'project_storages', [
            'filters' => json_encode([
                ['projectId' => ['operator' => '=', 'values' => [(string)$projectId]]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            return [];
        }
        $out = [];
        foreach ($collection['elements'] as $s) {
            if (($s['_type'] ?? null) !== 'ProjectStorage') {
                continue;
            }
            $folderHref = OpenProjectNormalizer::linkHref($s, 'projectFolder');
            $folderId   = null;
            if ($folderHref !== null) {
                $seg = OpenProjectNormalizer::trailingSegment($folderHref);
                $folderId = ctype_digit($seg) ? (int)$seg : null;
            }
            $out[] = [
                'id'                  => (int)($s['id'] ?? 0),
                'storageId'           => OpenProjectNormalizer::linkId($s, 'storage'),
                'storageName'         => OpenProjectNormalizer::linkTitle($s, 'storage'),
                'projectFolderMode'   => OpenProjectNormalizer::text((string)($s['projectFolderMode'] ?? 'inactive')),
                'projectFolderFileId' => $folderId,
                'openUrl'             => $this->client->absoluteUrl(OpenProjectNormalizer::linkHref($s, 'open')),
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function projectBody(string $name, string $identifier, string $description, bool $public, ?int $parentId): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new ValidationException('A project name is required (at most 255 characters)');
        }
        if (!self::isValidIdentifier($identifier)) {
            throw new ValidationException('The project identifier may only contain lowercase letters, digits, dashes and underscores, and must start with a letter or digit');
        }
        $body = [
            'name'       => $name,
            'identifier' => $identifier,
            'public'     => $public,
            'active'     => true,
        ];
        if (trim($description) !== '') {
            $body['description'] = ['format' => 'markdown', 'raw' => mb_substr(trim($description), 0, 10000)];
        }
        if ($parentId !== null && $parentId > 0) {
            $body['_links'] = ['parent' => ['href' => '/api/v3/projects/' . $parentId]];
        }
        return $body;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{jobId: string, status: string, message: ?string, projectId: ?int}
     */
    private function normaliseJobStatus(array $raw): array {
        if (($raw['_type'] ?? null) !== 'JobStatus') {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'expected a JobStatus resource');
        }
        $jobId = (string)($raw['jobId'] ?? '');
        if ($jobId === '') {
            $self  = OpenProjectNormalizer::linkHref($raw, 'self');
            $jobId = $self !== null ? OpenProjectNormalizer::trailingSegment($self) : '';
        }
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $jobId)) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'JobStatus without a usable job id');
        }
        $status = (string)($raw['status'] ?? '');
        if (!in_array($status, ['in_queue', 'in_process', 'success', 'error', 'failure'], true)) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'JobStatus with an unknown status');
        }
        $message   = isset($raw['message']) && is_string($raw['message'])
            ? mb_substr(OpenProjectNormalizer::text($raw['message']), 0, 300)
            : null;
        $payload   = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
        $projectId = OpenProjectNormalizer::linkId($payload, 'project');
        // A failed copy lists its reasons in payload.errors — the first is
        // the useful one for the creator.
        if ($message === null && is_array($payload['errors'] ?? null) && $payload['errors'] !== []) {
            $first = reset($payload['errors']);
            if (is_string($first)) {
                $message = mb_substr(OpenProjectNormalizer::text($first), 0, 300);
            }
        }
        return ['jobId' => $jobId, 'status' => $status, 'message' => $message, 'projectId' => $projectId];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{id:int, principalId:?int, principalType:string, principalName:string, roles:list<array{id:int, name:string}>}
     */
    private function normaliseMembership(array $raw): array {
        $principalHref = OpenProjectNormalizer::linkHref($raw, 'principal') ?? '';
        $type          = str_contains($principalHref, '/groups/') ? 'group'
            : (str_contains($principalHref, '/placeholder_users/') ? 'placeholder' : 'user');
        $roles = [];
        foreach ((array)($raw['_links']['roles'] ?? []) as $role) {
            if (!is_array($role) || !isset($role['href'])) {
                continue;
            }
            $seg = OpenProjectNormalizer::trailingSegment((string)$role['href']);
            if (ctype_digit($seg)) {
                $roles[] = ['id' => (int)$seg, 'name' => OpenProjectNormalizer::text((string)($role['title'] ?? ''))];
            }
        }
        return [
            'id'            => (int)($raw['id'] ?? 0),
            'principalId'   => OpenProjectNormalizer::linkId($raw, 'principal'),
            'principalType' => $type,
            'principalName' => OpenProjectNormalizer::linkTitle($raw, 'principal') ?? '',
            'roles'         => $roles,
        ];
    }

    /**
     * The one element of a users/principals collection whose login or email
     * equals what we are looking for, case-insensitively. Null otherwise.
     *
     * @param array<string,mixed> $raw
     * @return ?array{id:int, login:string, name:string, email:string}
     */
    private function exactUser(array $raw, string $login, string $email): ?array {
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            return null;
        }
        foreach ($collection['elements'] as $u) {
            if (($u['_type'] ?? null) !== 'User') {
                continue;
            }
            $uLogin = (string)($u['login'] ?? '');
            $uEmail = (string)($u['email'] ?? '');
            $hit = ($login !== '' && strcasecmp($uLogin, $login) === 0)
                || ($email !== '' && $uEmail !== '' && strcasecmp($uEmail, $email) === 0);
            if ($hit && (int)($u['id'] ?? 0) > 0) {
                return [
                    'id'    => (int)$u['id'],
                    'login' => OpenProjectNormalizer::text($uLogin),
                    'name'  => OpenProjectNormalizer::text((string)($u['name'] ?? '')),
                    'email' => OpenProjectNormalizer::text($uEmail),
                ];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $raw a project collection
     * @return list<array{id:int, identifier:string, name:string, active:bool, public:bool, canEditProject:bool, linkable:bool}>
     */
    private function projectSummaries(array $raw): array {
        $collection = OpenProjectNormalizer::collection($raw);
        if ($collection === null) {
            throw new OpenProjectException(OpenProjectException::UNSUPPORTED_RESPONSE, 'projects did not return a collection');
        }
        $out = [];
        foreach ($collection['elements'] as $element) {
            if (($element['_type'] ?? null) !== 'Project') {
                continue;
            }
            $summary = OpenProjectNormalizer::projectSummary($element);
            if ($summary['id'] > 0) {
                $out[] = $summary;
            }
        }
        return $out;
    }
}
