<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_roster_shift — one shift that needs covering.
 *
 * `publishedAt` of 0 means draft: visible to the team's admins and to nobody
 * else, and not written into anybody's presence. Non-zero is the record that
 * the roster was communicated, which Arbeidstijdenwet art. 4:2 requires to
 * exist in writing (DESIGN §2.233).
 *
 * @method int     getId()
 * @method void    setId(int $id)
 * @method string  getTeamId()
 * @method void    setTeamId(string $teamId)
 * @method string  getShiftDate()
 * @method void    setShiftDate(string $shiftDate)
 * @method int     getShiftTypeId()
 * @method void    setShiftTypeId(int $shiftTypeId)
 * @method ?int    getRoomId()
 * @method void    setRoomId(?int $roomId)
 * @method int     getRequiredCount()
 * @method void    setRequiredCount(int $requiredCount)
 * @method string  getNotes()
 * @method void    setNotes(string $notes)
 * @method int     getPublishedAt()
 * @method void    setPublishedAt(int $publishedAt)
 * @method string  getPublishedBy()
 * @method void    setPublishedBy(string $publishedBy)
 * @method string  getCreatedBy()
 * @method void    setCreatedBy(string $createdBy)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 */
class RosterShift extends Entity {

    protected string $teamId        = '';
    protected string $shiftDate     = '';
    protected int    $shiftTypeId   = 0;
    protected ?int   $roomId        = null;
    protected int    $requiredCount = 1;
    protected string $notes         = '';
    protected int    $publishedAt   = 0;
    protected string $publishedBy   = '';
    protected string $createdBy     = '';
    protected int    $createdAt     = 0;
    protected int    $updatedAt     = 0;

    public function __construct() {
        $this->addType('shiftTypeId',   'integer');
        $this->addType('roomId',        'integer');
        $this->addType('requiredCount', 'integer');
        $this->addType('publishedAt',   'integer');
        $this->addType('createdAt',     'integer');
        $this->addType('updatedAt',     'integer');
    }

    public function isPublished(): bool {
        return $this->publishedAt > 0;
    }
}
