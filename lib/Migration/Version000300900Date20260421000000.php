<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.9.0 — Rename teamhub_integration_registry → teamhub_integ_registry.
 *
 * This migration was written for the 1-2 live installs that had the old table
 * name. Those installs have been remediated manually. The rename logic has been
 * retired here to avoid IDBConnection API incompatibilities across NC versions.
 *
 * Fresh installs: Version000209000 creates the table under the correct short
 * name from the start, so nothing is needed here.
 */
class Version000300900Date20260421000000 extends SimpleMigrationStep {
    // Intentional no-op. See docblock above.
}
