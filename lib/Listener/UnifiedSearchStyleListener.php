<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Load `css/search.css` on every logged-in page (v4.10.4).
 *
 * Nextcloud's unified search renders a TeamHub result on whatever page the
 * user is on, and paints the result's icon from a CSS class the provider
 * names (`icon-teamhub`, the fifth `SearchResultEntry` argument). A class
 * only works if its stylesheet is on the page — so, exactly as Talk's
 * `UnifiedSearchCSSLoader` and Deck's global `deck.css` do, one 150-byte
 * stylesheet rides along on every page. Nothing else is added here; the
 * Files-app entry has its own listener (`FilesScriptsListener`).
 *
 * @template-implements IEventListener<Event>
 */
class UnifiedSearchStyleListener implements IEventListener {

    public function handle(Event $event): void {
        if (!$event instanceof BeforeTemplateRenderedEvent) {
            return;
        }
        if (!$event->isLoggedIn()) {
            return;
        }
        Util::addStyle(Application::APP_ID, 'search');
    }
}
