<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_mywork_state — per-user, per-item queue state (v4.5.21).
 *
 * This is the only table My Work owns, and since v4.5.40 it holds exactly one
 * thing that is *personal to TeamHub* rather than a fact about the source
 * resource:
 *
 *  - **snoozeUntil** — Unix seconds until which the item is hidden. 0 = not
 *    snoozed. Snoozing deliberately does NOT touch the source app: a Deck
 *    card's real due date is the team's shared truth and one member's decision
 *    to look at it tomorrow must not move it for everyone.
 *
 * **`followState` was removed in v4.5.40** along with the whole follow
 * feature. It was a tri-state — 0 default, 1 followed, 2 muted — and the trap
 * was that the *only* control which could reach it was a single toggle:
 * unfollowing wrote 2, not 0, so "stop following" silently meant "never show
 * me this item again", with no filter, no unhide and no purge to undo it.
 * Following itself pinned provider-excluded items (an undated Deck card) into
 * the queue. Neither half earned its complexity, so both are gone; see
 * Version000405040Date20260804000000.
 *
 * A row exists only once a user has acted; the absence of a row is the
 * default state for every item, which is why there is no reconcile job and no
 * cleanup coupling to Deck or Approval. Rows for resources that later vanish
 * are harmless — nothing joins against them.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method string getProviderId()
 * @method void   setProviderId(string $providerId)
 * @method string getItemId()
 * @method void   setItemId(string $itemId)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method int    getSnoozeUntil()
 * @method void   setSnoozeUntil(int $snoozeUntil)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 * @method int    getUpdatedAt()
 * @method void   setUpdatedAt(int $updatedAt)
 */
class MyWorkState extends Entity {

    protected string $userId      = '';
    protected string $providerId  = '';
    protected string $itemId      = '';
    protected string $teamId      = '';
    protected int    $snoozeUntil = 0;
    protected int    $createdAt   = 0;
    protected int    $updatedAt   = 0;

    public function __construct() {
        $this->addType('snoozeUntil', 'integer');
        $this->addType('createdAt',   'integer');
        $this->addType('updatedAt',   'integer');
    }

    /** True when the snooze is still in the future at $now. */
    public function isSnoozed(int $now): bool {
        return $this->snoozeUntil > $now;
    }
}
