<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v4.13.29 — a blank-canvas widget may declare header actions.
 *
 * One change, additive: `teamhub_integ_cap.actions`, a JSON array of the
 * link actions a capability declares at registration. NULL for every
 * capability that declares none, which is all of them until an app adds some.
 *
 * ## Why a column rather than a key in `presentation`
 *
 * `presentation` is documented on the table it lives on as "type-specific
 * render hints … never required to render", and that is what it holds: a
 * widget's preferred size. An action carries a **URL that TeamHub will send a
 * person to**, which is contract, not a hint — the same kind of thing as
 * `provider_class` and `iframe_url`, which each have a column for the same
 * reason. Burying a navigable URL in a bag of hints is how it ends up read by
 * something that never validated it.
 *
 * ## What it reverses
 *
 * `IframeWidgetCapabilityDefinition` shipped in v4.13.24 with a "No actions"
 * section arguing a canvas owns its interior, so its buttons belong inside the
 * frame and a header menu the frame knows nothing about would be two apps
 * drawing one card. That held until a real integration arrived: TOPdesk's
 * canvas put its action menu in the card body, where it reads as part of the
 * app's content rather than as the card's controls, and is a second menu
 * beside the collapse control TeamHub already draws. Justin's call, 2026-10-25.
 *
 * The objection was not wrong, and the answer to it is the restriction: a
 * declared action is a **link only**. There is no channel into the frame, so
 * the two apps cannot disagree about state — TeamHub renders a menu of
 * destinations it knows at registration and navigates; the frame keeps every
 * control that acts on its own content. See `IframeWidgetCapabilityDefinition`.
 *
 * Rule compliance (`/migrations`): a new column on an **existing** table, so
 * rule 1b applies and it is `notnull => false` — genuinely nullable, which is
 * the honest shape for a capability that declares nothing. No index and no
 * constraint, so rule 1's 30-character cap has nothing to bite on. No
 * `OCA\TeamHub\` constant or class is referenced (rule 2). No seed data
 * (rule 2a).
 */
class Version000413029Date20261025120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_integ_cap')) {
            return null;
        }

        $table = $schema->getTable('teamhub_integ_cap');
        if ($table->hasColumn('actions')) {
            return null;
        }

        $output->info('Version000413029: adding teamhub_integ_cap.actions');

        // TEXT, like `presentation` beside it: the list is short (ten at
        // most, enforced in the definition) but a label and a URL are both
        // free text and 2048 is already the iframe_url ceiling on its own.
        $table->addColumn('actions', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);

        return $schema;
    }
}
