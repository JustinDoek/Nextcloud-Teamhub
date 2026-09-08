<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\FileReviewService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Load TeamHub's "Request review" file action into the Files app (v4.8.18).
 *
 * This is the first time TeamHub puts anything on a page it does not render,
 * so it is deliberately the smallest thing that can work:
 *
 *  - **Nothing loads when the module is off.** The global switch is checked
 *    here, before a single byte is added to the page. An instance that does not
 *    want file reviews pays nothing for them, which is the least this listener
 *    owes every other user of the Files app.
 *  - **Nothing decides anything here.** Whether the action appears for a given
 *    file is settled in the browser, against the team-folder prefixes
 *    `/api/v1/file-reviews/scopes` returns. This listener only makes the script
 *    present.
 *
 * ## The translations line
 *
 * `Util::addTranslations()` is called because the **Files app** is the active
 * app on this page, so TeamHub's l10n bundle is not loaded the way it is on our
 * own pages. Without it every `t('teamhub', …)` in the entry falls back to
 * English while the rest of the page is in the user's language. This is the one
 * thing in this file that has not been confirmed against a running instance —
 * see FILE-REVIEW-PLAN.md §4.1. If the strings come out English, this call is
 * the first place to look, not the entry.
 *
 * ## Styles
 *
 * Two stylesheets, matching `templates/main.php`: `vite-index.chunk` is the
 * shared Nextcloud-component CSS every entry imports, and
 * `vite-filesactions.chunk` is this entry's own. CSS is extracted per entry
 * rather than inlined — see the header comment in `vite.config.mjs` for why
 * that is not optional in a multi-entry build.
 *
 * @template-implements IEventListener<Event>
 */
class FilesScriptsListener implements IEventListener {

    public function __construct(
        private FileReviewService $reviewService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        try {
            if (!$this->reviewService->isEnabledGlobally()) {
                return;
            }

            Util::addTranslations(Application::APP_ID);
            Util::addStyle(Application::APP_ID, 'vite-index.chunk');
            Util::addStyle(Application::APP_ID, 'vite-filesactions.chunk');
            Util::addScript(Application::APP_ID, 'filesactions');
        } catch (\Throwable $e) {
            // A failure here would break somebody's Files page for a feature
            // they may not even use. Swallowed and logged, exactly like
            // AppDisabledListener treats its own work.
            $this->logger->warning('[TeamHub][FilesScriptsListener] could not add the file review script', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }
}
