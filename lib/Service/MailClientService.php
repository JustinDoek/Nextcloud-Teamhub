<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Where a "write an email" control should send the current user (v4.6.26).
 *
 * ## Why this exists as its own service
 *
 * The same question — *can this person compose in Nextcloud Mail, or do we hand
 * off to whatever the operating system has?* — was answered in two places by
 * two copies of the same `oc_mail_accounts` lookup: `MemberService::
 * isMailAvailableForCurrentUser()` for the members widget, and
 * `TeamExpiryService::hasConfiguredMailAccount()` for the "Email owner" action.
 * The sidebar's "Email all members" would have been the third.
 *
 * Three copies of a rule is the shape DESIGN §2.97 calls out for sanitisers and
 * the reasoning transfers: when the rule changes — and this one already changed
 * once, in v4.6.17 — a fix has to be found in every copy. Both original call
 * sites now delegate here.
 *
 * ## The rule itself, and why it is what it is
 *
 * **Nextcloud Mail only when Mail is actually usable.** v4.6.16 asked
 * `isEnabledForUser('mail')`, which answers "is the app installed and switched
 * on" — not "can this person send a message with it". On an install where Mail
 * ships enabled and nobody has added an account (AIO does exactly this), that
 * answered a request to write one email with an IMAP setup wizard. Justin hit
 * it on 2026-08-09; `oc_mail_accounts` on the test instance holds zero rows.
 *
 * So the test is whether **the viewer** has at least one account in Mail. It is
 * per-user by necessity: Mail accounts are personal, and an administrator with
 * none needs the system handler even on an instance where their colleagues use
 * Mail every day.
 *
 * **Every uncertainty resolves to `mailto:`** — no Mail, no table, an
 * unreadable table, a route that has moved. The system handler is the one thing
 * we cannot misconfigure.
 *
 * ## What this owns, and what it does not
 *
 * This owns the *choice of client* and the shape of the URL. Subject, body and
 * recipients are the caller's. Either way the message is composed, read and
 * sent by the user in their own client — nothing is sent on their behalf and
 * nothing leaves the server here.
 */
class MailClientService {

	/**
	 * Mail's own `/mailto` route, which reads `to` / `cc` / `bcc` / `subject` /
	 * `body` off the query string and opens its composer with them.
	 *
	 * Verified against the shipped source of Mail 5.10.11 on the test instance:
	 * `appinfo/routes.php` declares `page#mailto`, `src/views/Home.vue` forwards
	 * those five query parameters to the composer route, and
	 * `MailboxThread.vue::stringToRecipients()` parses each address field with
	 * `addressParser.parse()` — a real RFC address-list parser, which is what
	 * makes a comma-separated list of recipients work.
	 *
	 * There is also a `page#compose?uri=mailto:…` route. It parses the URI in
	 * PHP and 302s to this one, so it is a redirect hop to reach the same
	 * place. We address `/mailto` directly.
	 */
	private const MAIL_ROUTE = 'mail.page.mailto';

	public function __construct(
		private IDBConnection  $db,
		private IUserSession   $userSession,
		private IAppManager    $appManager,
		private IURLGenerator  $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Does the current user have at least one account set up in Nextcloud Mail?
	 *
	 * Read straight from `oc_mail_accounts` rather than through Mail's own
	 * service classes: TeamHub must not hard-depend on another app's PHP API,
	 * and this is one indexed lookup on a table Mail has had since 1.x.
	 *
	 * A `SELECT` in a try/catch rather than an introspection call, because
	 * `DbIntrospectionService::getTableColumns()` is known to return `[]` for
	 * some tables on some installs — when the table's existence *is* the
	 * question, ask the table.
	 */
	public function isAvailableForCurrentUser(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		try {
			if (!$this->appManager->isEnabledForUser('mail', $user)) {
				return false;
			}
		} catch (\Throwable $e) {
			return false;
		}

		try {
			$qb  = $this->db->getQueryBuilder();
			$res = $qb->select('id')
				->from('mail_accounts')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
				->setMaxResults(1)
				->executeQuery();
			$row = $res->fetch();
			$res->closeCursor();

			return $row !== false;
		} catch (\Throwable $e) {
			// Table absent (Mail never installed) or unreadable. Either way the
			// answer this method exists to give is "no".
			$this->logger->debug('[TeamHub][MailClient] mail_accounts unreadable — assuming no Mail account', [
				'error' => $e->getMessage(), 'app' => Application::APP_ID,
			]);
			return false;
		}
	}

	/**
	 * Build a compose URL for one or more recipients.
	 *
	 * Recipients are joined with `,` — the separator RFC 6068 specifies for a
	 * `mailto:` address list, and the one Mail's address parser expects. The
	 * list is de-duplicated and emptied of blanks first; a caller passing a
	 * roster does not have to pre-clean it.
	 *
	 * **Every address is validated before it reaches the URL.** These come from
	 * `IUser::getEMailAddress()`, which is account data the user sets
	 * themselves — so it is untrusted input on its way into a URL the browser
	 * will then navigate to. `FILTER_VALIDATE_EMAIL` rejects anything carrying
	 * a space, `?`, `&`, `#` or a control character, which is exactly the set
	 * that could otherwise inject a second query parameter or split the
	 * address list. It is why the addresses can then be joined literally
	 * rather than percent-encoded individually: encoding the `@` is legal but
	 * not universally handled by OS mail handlers, and encoding the separating
	 * comma would collapse the list into one malformed address.
	 *
	 * Returns `null` when there is nobody to write to. That is a real state
	 * (a team where no account has an email address set — see HANDOFF §0-mail)
	 * and the caller is expected to say so rather than open an empty composer.
	 *
	 * @param string[] $recipients
	 */
	public function composeUrl(array $recipients, string $subject = '', string $body = ''): ?string {
		$clean = [];
		foreach ($recipients as $address) {
			$address = trim((string)$address);
			if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
				continue;
			}
			// Case-insensitive de-dup, but keep the address as written: the
			// local part is technically case-sensitive and we are not the ones
			// to normalise somebody's address.
			$key = strtolower($address);
			if (!isset($clean[$key])) {
				$clean[$key] = $address;
			}
		}

		if ($clean === []) {
			return null;
		}

		$to = implode(',', array_values($clean));

		if ($this->isAvailableForCurrentUser()) {
			try {
				$params = ['to' => $to];
				if ($subject !== '') {
					$params['subject'] = $subject;
				}
				if ($body !== '') {
					$params['body'] = $body;
				}
				return $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->linkToRoute(self::MAIL_ROUTE) . '?' . http_build_query($params),
				);
			} catch (\Throwable $e) {
				// Route absent on this Mail version. Expected rather than
				// exceptional — the mailto: below is the point of the fallback.
				$this->logger->debug('[TeamHub][MailClient] Mail route unavailable — falling back to mailto:', [
					'error' => $e->getMessage(), 'app' => Application::APP_ID,
				]);
			}
		}

		$mailto = 'mailto:' . $to;
		$query  = [];
		if ($subject !== '') {
			$query['subject'] = $subject;
		}
		if ($body !== '') {
			$query['body'] = $body;
		}

		return $query === []
			? $mailto
			: $mailto . '?' . http_build_query($query);
	}
}
