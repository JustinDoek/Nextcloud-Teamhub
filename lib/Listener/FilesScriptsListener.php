<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Service\DecisionTeamService;
use OCA\TeamHub\Service\FileReviewService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Load TeamHub's file actions into the Files app (v4.8.18; a second action in
 * v4.11.42).
 *
 * This is the first time TeamHub puts anything on a page it does not render,
 * so it is deliberately the smallest thing that can work:
 *
 *  - **Nothing loads when both modules are off.** The two global switches are
 *    checked here, before a single byte is added to the page. An instance that
 *    wants neither file reviews nor decisions pays nothing for them, which is
 *    the least this listener owes every other user of the Files app.
 *  - **Nothing decides anything here.** Whether either action appears for a
 *    given file is settled in the browser, against the team-folder prefixes
 *    and per-team action lists `/api/v1/file-actions/scopes` returns. This
 *    listener only makes the script present.
 *
 * ## Why two switches rather than one
 *
 * Until v4.11.42 the only action was *Request review*, which is licensed, so
 * `FileReviewService::isEnabledGlobally()` could stand for "is there anything
 * to load". *Propose a decision* has no licence requirement, and gating it on
 * the review service would have hidden it on every unlicensed instance — a
 * licence check for a feature that does not need one, in the one place where
 * nobody would think to look for it.
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
 * ## No styles — on purpose (v4.10.11)
 *
 * Until 4.10.11 this listener also added `vite-index.chunk` (285 KB of shared
 * NC-component CSS) and `vite-filesactions.chunk`, and the entry itself pulled
 * the app's 2.5 MB vendor chunk — on every Files page load, including the one
 * inside TeamHub's own Files tab, for a menu entry most loads never open. The
 * entry that loads here is now a stub with no imports (`src/filesactions.js`)
 * that waits for the Files app to load and the page to go idle before it
 * imports the registration stage, and the dialog only on a click; Vite's
 * preload helper fetches each stage's chunk **and its CSS** itself, resolving
 * both through `OC.filePath()`. Adding a stylesheet here again would put the
 * weight back on every page for nothing — the Files page must stay as light
 * as if TeamHub were not installed.
 *
 * @template-implements IEventListener<Event>
 */
class FilesScriptsListener implements IEventListener {

    public function __construct(
        private FileReviewService $reviewService,
        private DecisionTeamService $decisionTeamService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        try {
            // Either action being possible anywhere on the instance is enough.
            // Whether it is possible for *this* file, in *this* team, is the
            // scopes call's answer, not this listener's.
            if (!$this->reviewService->isEnabledGlobally()
                && !$this->decisionTeamService->isModuleEnabledGlobally()) {
                return;
            }

            Util::addTranslations(Application::APP_ID);
            Util::addScript(Application::APP_ID, 'filesactions');
        } catch (\Throwable $e) {
            // A failure here would break somebody's Files page for a feature
            // they may not even use. Swallowed and logged, exactly like
            // AppDisabledListener treats its own work.
            $this->logger->warning('[TeamHub][FilesScriptsListener] could not add the file action script', [
                'error' => $e->getMessage(), 'app' => Application::APP_ID,
            ]);
        }
    }
}
