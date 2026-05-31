<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

/**
 * Thrown by RoomVoxClient on any booking failure. The message is
 * intended to be safe for direct display to end users — it carries
 * either RoomVox's own error string (already user-facing) or our
 * translated wrappers around auth / transport failures.
 */
class RoomVoxClientException extends \RuntimeException {
}
