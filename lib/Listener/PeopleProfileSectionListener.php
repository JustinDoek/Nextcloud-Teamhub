<?php
declare(strict_types=1);

namespace OCA\TeamHub\Listener;

use OCA\TeamHub\AppInfo\Application;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Profile\BeforeTemplateRenderedEvent;
use OCP\Util;

/**
 * Put the collaboration profile on the Nextcloud profile page (v4.13.52).
 *
 * ## Why this is not the thing §2.214 removed
 *
 * The first attempt registered a section through
 * `window.OCA.Profile.ProfileSections`, an **internal of the Profile app**
 * that nothing promises. It was removed for that reason and replaced with a
 * link action, and Justin's answer on 2026-10-30 was that he wants the fields
 * on the page, not a link away from it.
 *
 * `OCP\Profile\BeforeTemplateRenderedEvent` is a **documented OCP event**
 * (`@since 25.0.0`) that carries the id of the user whose profile is being
 * rendered. Listening to it and adding a script is supported. What the script
 * then does to the page is ours, and it is written to fail quietly: if the
 * markup it looks for is not there, no section appears and nothing else on the
 * page is affected. That is the difference that matters -- the old version
 * could break when somebody else's registry changed shape, this one can only
 * decline to render.
 *
 * ## Nothing loads when the module is off
 *
 * Checked here, before a byte is added, the same bargain
 * `FilesScriptsListener` strikes with the Files app: an instance that does not
 * use People pays nothing for it on a page it did not ask us to touch.
 */
class PeopleProfileSectionListener implements IEventListener {
    public function __construct(
        private IConfig $config,
        private IInitialState $initialState,
    ) {}

    public function handle(Event $event): void {
        if (!$event instanceof BeforeTemplateRenderedEvent) {
            return;
        }
        if ($this->config->getAppValue(Application::APP_ID, 'presence_module_enabled', '1') !== '1') {
            return;
        }

        // The id of the profile being rendered. The script falls back to
        // reading it from the URL if this does not reach the page, because
        // this is another app's template and we do not own its render path.
        $this->initialState->provideInitialState('people_profile_user', $event->getUserId());

        Util::addScript(Application::APP_ID, 'peopleprofile');
    }
}
