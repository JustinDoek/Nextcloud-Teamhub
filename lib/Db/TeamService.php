<?php
declare(strict_types=1);

namespace OCA\TeamHub\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of `teamhub_team_service` (v4.10.31, the service builder;
 * `docs/service-builder.md`).
 *
 * A service a service team built. `draft` is what the builder edits;
 * `published` is what requests start from, copied from the draft by a
 * publish that also raises `pubVersion`. Both are JSON documents whose shape
 * `TeamServiceBuilder::normalise()` owns — this entity only decodes them.
 *
 * @method string  getTeamId()
 * @method void    setTeamId(string $v)
 * @method ?string getDraft()
 * @method void    setDraft(?string $v)
 * @method ?string getPublished()
 * @method void    setPublished(?string $v)
 * @method int     getPubVersion()
 * @method void    setPubVersion(int $v)
 * @method int     getListed()
 * @method void    setListed(int $v)
 * @method int     getSortOrder()
 * @method void    setSortOrder(int $v)
 * @method string  getCreatedBy()
 * @method void    setCreatedBy(string $v)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $v)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $v)
 * @method string  getPublishedBy()
 * @method void    setPublishedBy(string $v)
 * @method int     getPublishedAt()
 * @method void    setPublishedAt(int $v)
 */
class TeamService extends Entity {

    protected string  $teamId      = '';
    protected ?string $draft       = null;
    protected ?string $published   = null;
    protected int     $pubVersion  = 0;
    protected int     $listed      = 0;
    protected int     $sortOrder   = 1;
    protected string  $createdBy   = '';
    protected int     $createdAt   = 0;
    protected int     $updatedAt   = 0;
    protected string  $publishedBy = '';
    protected int     $publishedAt = 0;

    public function __construct() {
        $this->addType('pubVersion',  'integer');
        $this->addType('listed',      'integer');
        $this->addType('sortOrder',   'integer');
        $this->addType('createdAt',   'integer');
        $this->addType('updatedAt',   'integer');
        $this->addType('publishedAt', 'integer');
    }

    public function isListed(): bool {
        return $this->getListed() === 1;
    }

    /** @return array<string, mixed> */
    public function draftDocument(): array {
        return self::decode($this->getDraft());
    }

    /** @return array<string, mixed> empty when never published */
    public function publishedDocument(): array {
        return self::decode($this->getPublished());
    }

    /** @return array<string, mixed> */
    private static function decode(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $doc = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($doc) ? $doc : [];
    }
}
