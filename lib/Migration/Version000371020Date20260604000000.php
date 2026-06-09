<?php
declare(strict_types=1);

namespace OCA\TeamHub\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v3.71.2 — Sidecar table linking attachments to messages.
 *
 * Until this version, files attached via PostMessageForm lived as URLs
 * embedded in the message body. There was no message_id → file_id link,
 * so on decision finalize we had no durable way to find the attached
 * files and copy them into .proposals/{decisionId}/.
 *
 * One row per attached file. The same file can be attached to many
 * messages (no UNIQUE on file_id alone). The (message_id, file_id) pair
 * is unique to prevent double-registration of the same file on the same
 * message.
 *
 * Table-name budget (DESIGN.md §2.5, 27-char ceiling):
 *   teamhub_msg_attach = 18 chars ✓
 */
class Version000371020Date20260604000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('teamhub_msg_attach')) {
            $table = $schema->createTable('teamhub_msg_attach');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
            ]);

            // The message this attachment belongs to.
            $table->addColumn('message_id', Types::BIGINT, [
                'notnull' => true,
            ]);

            // Nextcloud file_id of the uploaded file. Lives in the uploader's
            // personal "TeamHub Attachments" folder and is shared with the
            // team circle (per current PostMessageForm flow).
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => true,
            ]);

            // Display name at upload time. Kept denormalised so the source
            // list can render even if the original file is later renamed
            // or the user account that owns it is removed.
            $table->addColumn('file_name', Types::STRING, [
                'length'  => 255,
                'notnull' => true,
            ]);

            // Uploader uid. Used for audit and (potentially) per-uploader
            // bulk cleanup on account delete.
            $table->addColumn('uploaded_by', Types::STRING, [
                'length'  => 64,
                'notnull' => true,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);

            // Dominant read path — all attachments for one message.
            $table->addIndex(['message_id'], 'th_msg_attach_msg_idx');

            // Prevent double-registration of the same file on the same message.
            $table->addUniqueIndex(['message_id', 'file_id'], 'th_msg_attach_uniq');
        }

        return $schema;
    }
}
