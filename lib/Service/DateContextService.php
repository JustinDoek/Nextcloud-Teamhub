<?php

declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * The two facts the frontend needs before it can render a date the way the
 * reader expects: which locale formats it, and which zone it is read in.
 *
 * Why this exists. Nextcloud renders `lang` and `data-locale` as two separate
 * attributes on <html> because they are two separate settings — Personal
 * Settings has a Language dropdown (which words to use) and a Locale dropdown
 * (how to write dates and numbers). They diverge constantly: an English UI
 * with `nl_NL` dates is the ordinary European setup. Until v4.7.2 TeamHub read
 * neither, so every date in the app was formatted either in the browser's
 * locale or — in the twenty call sites that looked correct — in Nextcloud's
 * *language*, which is the attribute sitting next to the one they wanted.
 *
 * The locale needs no transport of its own on the Vue entry points:
 * `getCanonicalLocale()` from `@nextcloud/l10n` reads `data-locale` straight
 * off the document. It is published here anyway because `templates/timeline.php`
 * is server-rendered vanilla JS that cannot import the module, and because a
 * single source for both values is what stops the two halves drifting.
 *
 * The timezone genuinely needs transport. It lives in the user's `core`/
 * `timezone` preference and appears nowhere in the DOM, so without this the
 * browser's own zone is the only thing a script can see.
 *
 * @see \OCA\TeamHub\Service\TimezoneService for the server-side half — the
 *      same preference, resolved for cron and rendered documents.
 */
class DateContextService {

	public function __construct(
		private IInitialState $initialState,
		private IUserSession $userSession,
		private IFactory $l10nFactory,
		private TimezoneService $timezoneService,
	) {
	}

	/**
	 * The viewer's locale, canonical form (`nl-NL`, not `nl_NL`).
	 *
	 * Intl rejects the underscore form, so the conversion is not cosmetic.
	 * This mirrors exactly what `getCanonicalLocale()` does client-side, so
	 * the iframe and the Vue app agree on the value.
	 */
	public function locale(): string {
		return str_replace('_', '-', (string)$this->l10nFactory->findLocale());
	}

	/** The viewer's IANA timezone name, e.g. `Europe/Amsterdam`. */
	public function timezone(): string {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		return $this->timezoneService->forUser($uid)->getName();
	}

	/**
	 * Both values as a plain array, for templates that render their own
	 * script rather than mounting a Vue entry point.
	 *
	 * @return array{locale: string, timezone: string}
	 */
	public function toArray(): array {
		return [
			'locale'   => $this->locale(),
			'timezone' => $this->timezone(),
		];
	}

	/**
	 * Publish both values as initial state, for the Vue entry points.
	 *
	 * Read client-side with `loadState('teamhub', 'dateContext')`. Called from
	 * every entry point that mounts a bundle which renders a date — the page
	 * controller, and both settings pages.
	 */
	public function provideInitialState(): void {
		$this->initialState->provideInitialState('dateContext', $this->toArray());
	}
}
