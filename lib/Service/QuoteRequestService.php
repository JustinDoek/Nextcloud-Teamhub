<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\LicensingRequestException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Sends a quote request from Admin → TeamHub → License to the licensing
 * service (v4.14.12).
 *
 * The administrator supplies five things: whether they want a licence or
 * support only, an e-mail address for the quotation, the country, the seats
 * they intend to license, and a comment. This service adds the two things a
 * quotation is priced and bound on, which the administrator is not asked to
 * type: the instance UUID the key will be bound to, and the number of unique
 * team members today. They are added here, on the server, so the browser
 * cannot alter them and the form does not have to show them as fields.
 *
 * The receiver is `portal.doekworks.eu/api/quote-request`, beside the seat
 * report. It files the request in the same `enquiries` table the website's
 * `quote.php` writes to and mails sales, so a request from the app and one
 * from the website land in the same place. Contract:
 * `docs/licensing-requests-contract.md`.
 *
 * No VAT number and no VIES lookup (Justin, 2026-11-10): the VAT check happens
 * after the quotation has been sent, by hand, not on the request.
 */
class QuoteRequestService {

    public const REQUEST_URL = 'https://portal.doekworks.eu/api/quote-request';

    /** `occ config:app:set teamhub quote_request_url --value https://…` */
    private const CFG_URL_OVERRIDE = 'quote_request_url';

    public const KINDS       = ['licence', 'support'];
    /**
     * The support plan a licence quote asks for (v4.14.12). `none` is the
     * Included level every licence carries; `supported` and `priority` are
     * the paid plans on the website's pricing page. Support only has no plan
     * choice here: it is a plan already.
     */
    public const SUPPORT_PLANS = ['none', 'supported', 'priority'];
    public const MESSAGE_MAX = 2000;
    public const SEATS_MAX   = 100000;
    public const EMAIL_MAX   = 254;

    public function __construct(
        private IConfig               $config,
        private LicensingPortalClient $portal,
        private LicenseService        $licenseService,
        private TelemetryService      $telemetryService,
        private LoggerInterface       $logger,
    ) {}

    /**
     * Check and normalise what the administrator typed.
     *
     * Static and pure so every rule is pinned in a unit test. Returns the
     * cleaned fields, or the first failing field's name under `error`. The
     * receiver checks the same rules again; this copy exists so the
     * administrator is told in their own language, before anything leaves.
     *
     * Seats are required for a licence and dropped for support only, which
     * the website's pricing defines as "any size, no seat count". The support
     * plan likewise belongs to a licence only, and defaults to `none`.
     *
     * @return array{error: string}|array{kind: string, email: string, country: string, seats: ?int, support_plan: ?string, message: string}
     */
    public static function validate(string $kind, string $email, string $country, mixed $seats, string $message, string $supportPlan = 'none'): array {
        $kind = trim($kind);
        if (!in_array($kind, self::KINDS, true)) {
            return ['error' => 'kind'];
        }

        $email = self::cleanEmail($email);
        if ($email === null) {
            return ['error' => 'email'];
        }

        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return ['error' => 'country'];
        }

        $seatCount = null;
        if ($kind === 'licence') {
            if (is_string($seats)) {
                $seats = trim($seats);
                $seats = ctype_digit($seats) ? (int)$seats : null;
            }
            if (!is_int($seats) || $seats < 1 || $seats > self::SEATS_MAX) {
                return ['error' => 'seats'];
            }
            $seatCount = $seats;
        }

        $plan = null;
        if ($kind === 'licence') {
            $plan = trim($supportPlan) === '' ? 'none' : trim($supportPlan);
            if (!in_array($plan, self::SUPPORT_PLANS, true)) {
                return ['error' => 'support_plan'];
            }
        }

        $message = self::cleanMessage($message);
        if ($message === null) {
            return ['error' => 'message'];
        }

        return [
            'kind'    => $kind,
            'email'   => $email,
            'country' => $country,
            'seats'   => $seatCount,
            'support_plan' => $plan,
            'message' => $message,
        ];
    }

    /** A trimmed address, or null when it is not one. Shared with the trial. */
    public static function cleanEmail(string $email): ?string {
        $email = trim($email);
        if ($email === '' || strlen($email) > self::EMAIL_MAX
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $email;
    }

    /** A trimmed comment with LF line ends, or null when too long. */
    public static function cleanMessage(string $message): ?string {
        $message = trim(str_replace("\r\n", "\n", $message));
        return mb_strlen($message) > self::MESSAGE_MAX ? null : $message;
    }

    /**
     * Send a validated request. Returns the reference the service filed it
     * under, which the License tab shows so the administrator can quote it.
     *
     * @param array{kind: string, email: string, country: string, seats: ?int, support_plan: ?string, message: string} $fields
     * @return array{reference: string}
     * @throws LicensingRequestException
     */
    public function send(array $fields): array {
        $answer = $this->portal->post(
            $this->portal->url(self::REQUEST_URL, self::CFG_URL_OVERRIDE),
            $this->buildPayload($fields),
        );

        $ref = $answer['json']['reference'] ?? null;
        if (in_array($answer['code'], [200, 201], true) && is_string($ref) && $ref !== '') {
            return ['reference' => substr($ref, 0, 32)];
        }

        $failure = LicensingPortalClient::failureFor($answer);
        if ($failure->getReason() === 'rejected') {
            $this->logger->warning('[TeamHub][QuoteRequestService] The licensing service refused a field the app accepted: ' . $failure->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
        throw $failure;
    }

    /**
     * The body that is posted. Public so the test can pin exactly what leaves
     * the instance: the administrator's six fields, the UUID, the member
     * count and two version strings. No user names, no team names.
     *
     * @param array{kind: string, email: string, country: string, seats: ?int, support_plan: ?string, message: string} $fields
     */
    public function buildPayload(array $fields): array {
        return [
            'kind'        => $fields['kind'],
            'email'       => $fields['email'],
            'country'     => $fields['country'],
            'seats'       => $fields['seats'],
            'support_plan' => $fields['support_plan'],
            'message'     => $fields['message'],
            'uuid'        => $this->licenseService->getInstanceUuid(),
            'members'     => $this->telemetryService->countUniqueTeamMembers(),
            'app_version' => $this->config->getAppValue(Application::APP_ID, 'installed_version', 'unknown'),
            'nc_version'  => (string)$this->config->getSystemValue('version', 'unknown'),
        ];
    }
}
