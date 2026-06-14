<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for teamhub_dec_meetings — approver-meeting relation on a proposal.
 *
 * Each row records that a meeting was scheduled via the suggest-meeting wizard
 * for the purpose of discussing the referenced decision/proposal. The
 * event_uid points to the iCalendar VEVENT created in the team calendar.
 *
 * meetingTitle and meetingStart are denormalised at insert time so the
 * proposal detail panel can render the "Scheduled meetings" section without
 * a CalDAV lookup per row. The calendar event remains the source of truth.
 *
 * @method int    getId()
 * @method void   setId(int $id)
 * @method string getTeamId()
 * @method void   setTeamId(string $teamId)
 * @method int    getDecisionId()
 * @method void   setDecisionId(int $decisionId)
 * @method string getEventUid()
 * @method void   setEventUid(string $eventUid)
 * @method string getMeetingTitle()
 * @method void   setMeetingTitle(string $meetingTitle)
 * @method int    getMeetingStart()
 * @method void   setMeetingStart(int $meetingStart)
 * @method string getScheduledBy()
 * @method void   setScheduledBy(string $scheduledBy)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class DecisionMeeting extends Entity {

    protected string $teamId        = '';
    protected int    $decisionId    = 0;
    protected string $eventUid      = '';
    protected string $meetingTitle  = '';
    protected int    $meetingStart  = 0;
    protected string $scheduledBy   = '';
    protected int    $createdAt     = 0;

    public function __construct() {
        $this->addType('decisionId',   'integer');
        $this->addType('meetingStart', 'integer');
        $this->addType('createdAt',    'integer');
    }
}
