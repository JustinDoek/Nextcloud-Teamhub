<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_team_expiry — a team's optional expiration date (v4.6.13).
 *
 * The row's *existence* is the fact: a team with no expiry has no row. There
 * is deliberately no "cleared" state to interpret, which is why `expiresAt` is
 * a plain int rather than nullable.
 *
 * `setBy`/`setAt` record who first put an expiry on the team; they are never
 * overwritten by an extension. `lastExtendedBy`/`lastExtendedAt` are null until
 * the first extension and then always describe the most recent one — the admin
 * grid shows both because "who decided this team should expire" and "who last
 * pushed it back" answer different questions.
 *
 * Note the entity property is `teamId` while the table's primary key is
 * `team_id`; this table has no surrogate id, so QBMapper's `id` handling is
 * unused and the mapper reads and writes by team id explicitly.
 *
 * @method string   getTeamId()
 * @method void     setTeamId(string $teamId)
 * @method int      getExpiresAt()
 * @method void     setExpiresAt(int $expiresAt)
 * @method string   getSetBy()
 * @method void     setSetBy(string $setBy)
 * @method int      getSetAt()
 * @method void     setSetAt(int $setAt)
 * @method ?string  getLastExtendedBy()
 * @method void     setLastExtendedBy(?string $lastExtendedBy)
 * @method ?int     getLastExtendedAt()
 * @method void     setLastExtendedAt(?int $lastExtendedAt)
 */
class TeamExpiry extends Entity {

    protected string  $teamId         = '';
    protected int     $expiresAt      = 0;
    protected string  $setBy          = '';
    protected int     $setAt          = 0;
    protected ?string $lastExtendedBy = null;
    protected ?int    $lastExtendedAt = null;

    public function __construct() {
        $this->addType('expiresAt',      'integer');
        $this->addType('setAt',          'integer');
        $this->addType('lastExtendedAt', 'integer');
    }
}
