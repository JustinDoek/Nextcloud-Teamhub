<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\PolicyField;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The Confidential files bridge behind `PolicyField::CONFIDENTIAL_TAG` (v4.8.24).
 *
 * A policy profile can name a classification tag; applying the profile puts that
 * tag on the team's group folder. This class owns everything that knows how the
 * Confidential files app stores its vocabulary and how Nextcloud stores a tag,
 * so `PolicyApplyService` and `PolicyService` can talk in tag ids and never in
 * either app's internals.
 *
 * ── What the tag is ──────────────────────────────────────────────────────
 *
 * **A Nextcloud system tag id, as a string.** Not a name. Two independent
 * confirmations, both read from the installed app rather than from
 * documentation: `HookListener` passes a label's `tag` straight into
 * `ISystemTagObjectMapper::assignTags()`, whose third argument is ids; and the
 * app's own admin screen saves `String(tag.id)` and reloads by matching
 * `String(tag.id) === String(label.tag)`. Storing the name instead would have
 * created a duplicate tag the first time anything called `createTag()`, because
 * Nextcloud identifies a tag by (name, userVisible, userAssignable) and not by
 * name alone.
 *
 * ── What applying it achieves ────────────────────────────────────────────
 *
 * Nextcloud has no tag inheritance — HANDOFF records that, and it is still true:
 * the files inside a tagged folder are not tagged and show nothing in the Files
 * UI. The mechanism that gives the folder tag teeth is the workflow engine.
 * `OCA\WorkflowEngine\Check\FileSystemTags::getFileIds()` recurses up the path
 * and collects every ancestor's tags before evaluating its condition, so a Files
 * Access Control, retention or automated-tagging rule keyed on the tag matches
 * every file in the team folder. TeamHub sets the classification; the
 * administrator's own rules decide what it costs. Nothing here creates a rule,
 * and the product must not claim it enforces one.
 *
 * ── Why the vocabulary is the label set and not every system tag ─────────
 *
 * Confidential files' own picker offers all system tags, and TeamHub's does not:
 * it offers the tags that a configured classification label points at. That is
 * what makes requiring the app a real precondition rather than a decoration —
 * a tag nothing classifies against is a tag no rule is keyed on, and offering
 * one would let an administrator believe they had classified a team when they
 * had only coloured it.
 *
 * ── Three states, not two ────────────────────────────────────────────────
 *
 * An administrator can be in any of: app absent, app present with no labels
 * configured, app present and usable. The middle one is the trap DESIGN §2.89
 * names and the v4.6.16 Mail bug shipped — installed is not usable. The test
 * instance is in exactly that state today (`files_confidential` enabled,
 * `labels` an empty array), so an availability probe that stopped at "is the app
 * there" would offer an empty picker with nothing to explain it.
 */
class ConfidentialFilesService {

    /** Nextcloud's object type for a file or folder in the tag mapper. */
    private const OBJECT_TYPE_FILES = 'files';

    /** Resolved once per request; null until {@see loadLabelTagIds} has run. */
    private ?array $labelTagIds = null;

    public function __construct(
        private IAppManager             $appManager,
        private IAppConfig              $appConfig,
        private ISystemTagManager       $tagManager,
        private ISystemTagObjectMapper  $tagObjectMapper,
        private ContainerInterface      $container,
        private LoggerInterface         $logger,
    ) {
    }

    // -------------------------------------------------------------------------
    // Availability
    // -------------------------------------------------------------------------

    /**
     * Is the Confidential files app installed and switched on for anybody.
     *
     * `isEnabledForAnyone()` rather than `isInstalled()`: the latter is
     * deprecated as of Nextcloud 32 and our floor is 33. This is an
     * instance-level control, so a per-user check would be the wrong question.
     */
    public function isAppAvailable(): bool {
        return $this->appManager->isEnabledForAnyone(PolicyField::APP_FILES_CONFIDENTIAL);
    }

    /**
     * Everything the profile editor needs to render the field or explain why it
     * cannot.
     *
     * Deliberately one call. The panel has to distinguish three states and a
     * shape that returned only the options would make "no app" and "no labels"
     * both arrive as an empty array — indistinguishable at the point where the
     * difference is the entire message.
     *
     * @return array{
     *     appAvailable: bool,
     *     labelsConfigured: bool,
     *     tags: list<array{id: string, name: string, userAssignable: bool}>
     * }
     */
    public function availability(): array {
        if (!$this->isAppAvailable()) {
            return [
                'appAvailable'     => false,
                'labelsConfigured' => false,
                'tags'             => [],
            ];
        }

        $tags = $this->tagOptions();

        return [
            'appAvailable' => true,
            // Read off the label list, not off `$tags`: a label pointing at a
            // tag somebody has since deleted still means the administrator has
            // configured classification. Collapsing the two would tell them to
            // go and define labels they already have.
            'labelsConfigured' => $this->loadLabelTagIds() !== [],
            'tags'             => $tags,
        ];
    }

    /**
     * The tags a profile may choose from: those a classification label points
     * at, resolved to their current names.
     *
     * A label naming a tag that no longer exists is dropped rather than shown
     * as a blank row — `getTagsByIds()` throws on the whole batch if any id is
     * missing, so the miss is caught and the batch retried one id at a time
     * rather than costing the administrator the entire list.
     *
     * @return list<array{id: string, name: string, userAssignable: bool}>
     */
    public function tagOptions(): array {
        $ids = $this->loadLabelTagIds();
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->resolveTags($ids) as $tag) {
            $out[] = [
                'id'   => $tag->getId(),
                'name' => $tag->getName(),
                // Shown in the picker because it changes what the classification
                // is worth: a userAssignable tag can be taken off the folder by
                // anyone who can edit tags, an unassignable one only by an
                // administrator. The field is ASSERTED either way — we report a
                // change, we never refuse one — but the two are not equally
                // fragile and an administrator choosing between them should see
                // which is which.
                'userAssignable' => $tag->isUserAssignable(),
            ];
        }

        return $out;
    }

    /**
     * Is this tag id one a classification label actually names.
     *
     * The save path's validation. A tag that exists in Nextcloud but that no
     * label points at is refused, for the reason in the class docblock: it is
     * not part of the classification scheme, so nothing is keyed on it.
     */
    public function isSelectableTag(string $tagId): bool {
        return in_array($tagId, $this->loadLabelTagIds(), true);
    }

    /**
     * The display name for one tag id, or null when it no longer exists.
     *
     * For rendering a stored value whose tag has since been deleted — the
     * profile keeps the id, and the screen says the tag is gone rather than
     * showing a bare number.
     */
    public function tagName(string $tagId): ?string {
        $tags = $this->resolveTags([$tagId]);

        return $tags === [] ? null : $tags[0]->getName();
    }

    // -------------------------------------------------------------------------
    // Reading what is on a folder
    // -------------------------------------------------------------------------

    /**
     * Tag ids currently on each of these file ids.
     *
     * **One query for the whole set**, which is what lets the compliance sweep
     * add this field without adding a per-team lookup — `TRACK-F2-DESIGN.md`
     * §5.2's rule, and the reason the "mandatory team folder" field was rejected
     * on cost while this one is not.
     *
     * @param list<int> $fileIds
     * @return array<int, list<string>> file id => tag ids
     */
    public function tagsForFiles(array $fileIds): array {
        $fileIds = array_values(array_filter($fileIds, static fn (int $id): bool => $id > 0));
        if ($fileIds === []) {
            return [];
        }

        try {
            $raw = $this->tagObjectMapper->getTagIdsForObjects(
                array_map('strval', $fileIds),
                self::OBJECT_TYPE_FILES,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ConfidentialFilesService] tagsForFiles failed', [
                'count' => count($fileIds),
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }

        $out = [];
        foreach ($raw as $fileId => $tagIds) {
            $out[(int)$fileId] = array_values(array_map('strval', $tagIds));
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Put `$tagId` on the folder, taking off any of `$supersedes` it carries.
     *
     * `$supersedes` is how a reassignment leaves one classification behind
     * rather than an accumulation: the caller passes the tags the *other*
     * profiles govern, and only those are removed. A tag put there by
     * Confidential files' own content classification, or by hand, is not in that
     * list and survives — this method never strips a tag it did not put on.
     *
     * Returns false, having logged, when the write could not be made. The caller
     * is an apply run that has already changed other settings, so a tag failure
     * is reported rather than thrown: rolling back the rest would be worse, and
     * the drift scan will show the folder as non-conformant either way.
     *
     * @param list<string> $supersedes
     */
    public function applyTag(int $fileId, string $tagId, array $supersedes = []): bool {
        if ($fileId <= 0) {
            return false;
        }

        $remove = array_values(array_diff(
            array_intersect($this->tagsOn($fileId), $supersedes),
            [$tagId],
        ));

        try {
            if ($remove !== []) {
                $this->tagObjectMapper->unassignTags((string)$fileId, self::OBJECT_TYPE_FILES, $remove);
            }
            $this->tagObjectMapper->assignTags((string)$fileId, self::OBJECT_TYPE_FILES, [$tagId]);
        } catch (TagNotFoundException $e) {
            // The tag was deleted between the profile being saved and applied.
            // Not an error in this code and not something the administrator can
            // fix from here, so it is reported rather than thrown.
            $this->logger->warning('[TeamHub][ConfidentialFilesService] tag no longer exists', [
                'fileId' => $fileId,
                'tagId'  => $tagId,
                'app'    => Application::APP_ID,
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ConfidentialFilesService] applyTag failed', [
                'fileId' => $fileId,
                'tagId'  => $tagId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Take `$tagIds` off the folder, ignoring any it does not carry.
     *
     * Used when a team is cleared back to unclassified. Only tags the profile
     * governed are passed in, for the same reason `applyTag()` scopes its
     * removals: TeamHub takes back what it put there and nothing else.
     *
     * @param list<string> $tagIds
     */
    public function removeTags(int $fileId, array $tagIds): bool {
        if ($fileId <= 0 || $tagIds === []) {
            return false;
        }

        $remove = array_values(array_intersect($this->tagsOn($fileId), $tagIds));
        if ($remove === []) {
            return false;
        }

        try {
            $this->tagObjectMapper->unassignTags((string)$fileId, self::OBJECT_TYPE_FILES, $remove);
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ConfidentialFilesService] removeTags failed', [
                'fileId' => $fileId,
                'error'  => $e->getMessage(),
                'app'    => Application::APP_ID,
            ]);
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function tagsOn(int $fileId): array {
        return $this->tagsForFiles([$fileId])[$fileId] ?? [];
    }

    /**
     * Resolve tag ids to tags, dropping the ones that no longer exist.
     *
     * `getTagsByIds()` throws `TagNotFoundException` for the whole batch if a
     * single id is missing, so one deleted tag would otherwise empty the
     * picker. The retry costs one query per id and only ever runs on an
     * instance that has actually deleted a classified tag.
     *
     * @param list<string> $ids
     * @return list<ISystemTag>
     */
    private function resolveTags(array $ids): array {
        if ($ids === []) {
            return [];
        }

        try {
            return array_values($this->tagManager->getTagsByIds($ids));
        } catch (TagNotFoundException $e) {
            // Fall through to the per-id pass below.
        } catch (\Throwable $e) {
            $this->logger->warning('[TeamHub][ConfidentialFilesService] getTagsByIds failed', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            try {
                foreach ($this->tagManager->getTagsByIds([$id]) as $tag) {
                    $out[] = $tag;
                }
            } catch (\Throwable $e) {
                // One dead id. Skipped by design — see the docblock.
            }
        }

        return $out;
    }

    /**
     * The tag ids the app's classification labels point at.
     *
     * **The app's own service first, its stored config as a fallback.** The
     * service is Confidential files' supported surface and survives a change to
     * how the labels are stored; the fallback survives the class being moved or
     * renamed. Neither alone is safe: reading the config only would silently
     * follow a stale format, and calling the service only would grey out a
     * working feature the day they refactor. The fallback logs, so a switch
     * between the two is visible rather than silent.
     *
     * @return list<string>
     */
    private function loadLabelTagIds(): array {
        if ($this->labelTagIds !== null) {
            return $this->labelTagIds;
        }

        $this->labelTagIds = [];

        if (!$this->isAppAvailable()) {
            return $this->labelTagIds;
        }

        try {
            /** @psalm-suppress MixedAssignment */
            $settings = $this->container->get('OCA\\Files_Confidential\\Service\\SettingsService');
            /** @var list<string> $tags */
            $tags = $settings->getTags();
            $this->labelTagIds = $this->cleanIds($tags);

            return $this->labelTagIds;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[TeamHub][ConfidentialFilesService] Files_Confidential SettingsService unavailable — falling back to stored config',
                [
                    'error' => $e->getMessage(),
                    'app'   => Application::APP_ID,
                ],
            );
        }

        // The app stores its labels as one lazy JSON value. Only `tag` is read
        // here; the matching rules are entirely the other app's business.
        $raw = $this->appConfig->getValueString(
            PolicyField::APP_FILES_CONFIDENTIAL,
            'labels',
            '[]',
            true,
        );

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('[TeamHub][ConfidentialFilesService] could not decode labels', [
                'error' => $e->getMessage(),
                'app'   => Application::APP_ID,
            ]);
            return $this->labelTagIds;
        }

        if (!is_array($decoded)) {
            return $this->labelTagIds;
        }

        $tags = [];
        foreach ($decoded as $label) {
            if (is_array($label) && isset($label['tag'])) {
                $tags[] = (string)$label['tag'];
            }
        }

        $this->labelTagIds = $this->cleanIds($tags);

        return $this->labelTagIds;
    }

    /**
     * Drop blanks and duplicates, preserving the order the labels are in — that
     * order is the app's own importance ranking and the picker should not
     * re-sort it into something meaningless.
     *
     * **Deduplicated by value, never by array key.** A system tag id is a
     * numeric string, and PHP silently casts a numeric string array key to an
     * integer — so the obvious `$seen[$id] = true` + `array_keys()` returns
     * `[1]` where the caller needs `['1']`. Every strict comparison downstream
     * then fails: `isSelectableTag('1')` was `in_array('1', [1], true)`, which
     * is false, so saving a profile with a perfectly valid tag was refused with
     * "That tag is not used by any Confidential files classification label."
     *
     * It failed *only* at that check, which is what made it confusing: the
     * picker resolved and displayed the tag correctly, because
     * `getTagsByIds()` accepts integers and returns string ids either way.
     * Shipped in v4.8.24 and found on the instance the same day.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private function cleanIds(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
