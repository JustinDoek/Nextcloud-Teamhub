<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\LicensingRequestException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Admin → TeamHub → License → *Request a trial key* (v4.14.12).
 *
 * Asks the licensing service for this instance's trial and installs the key
 * it answers with, in one step. The administrator gives an e-mail address and
 * nothing else; the instance UUID is this server's own.
 *
 * Every instance gets **one** trial, for ever: the portal's
 * `thp_trial_registry` is unique per UUID, the same table the partner portal's
 * trial button writes. A second request is answered `trial_used`, and the
 * License tab then offers to ask for an extended trial, which is the same call
 * with `extension` set: no key, a request filed with sales.
 *
 * The key is a one-month, 100-seat trial, signed by the portal with the same
 * function every other key is signed with (`lic_sign_version()`), and it goes
 * through `LicenseService::saveKey()` like a pasted one, so it is verified
 * against the embedded public key and this instance's UUID before it is
 * stored. Nothing about the trial is trusted because of where it came from.
 *
 * **A paid key is never replaced.** saveKey() overwrites whatever is there,
 * so a request while a valid paid licence is installed is refused here,
 * before anything leaves (`licensed`), and the tab does not show the button.
 *
 * Contract: `docs/licensing-requests-contract.md`.
 */
class TrialRequestService {

    public const REQUEST_URL = 'https://portal.doekworks.eu/api/trial-request';

    /** `occ config:app:set teamhub trial_request_url --value https://…` */
    private const CFG_URL_OVERRIDE = 'trial_request_url';

    public function __construct(
        private IConfig               $config,
        private LicensingPortalClient $portal,
        private LicenseService        $licenseService,
        private TelemetryService      $telemetryService,
        private LoggerInterface       $logger,
    ) {}

    /**
     * @return array{error: string}|array{email: string, extension: bool, message: string}
     */
    public static function validate(string $email, bool $extension, string $message): array {
        $clean = QuoteRequestService::cleanEmail($email);
        if ($clean === null) {
            return ['error' => 'email'];
        }
        $text = QuoteRequestService::cleanMessage($message);
        if ($text === null) {
            return ['error' => 'message'];
        }
        return ['email' => $clean, 'extension' => $extension, 'message' => $extension ? $text : ''];
    }

    /**
     * Whether a trial may be requested at all: not while a paid key is in
     * force. Expired paid keys and trials do not block; the portal decides
     * whether this UUID still has its trial.
     */
    public function blockedByPaidKey(): bool {
        $status = $this->licenseService->getStatus();
        return !empty($status['hasKey'])
            && empty($status['isTrial'])
            && in_array($status['enforcementLevel'] ?? '', ['none', 'grace'], true);
    }

    /**
     * @param array{email: string, extension: bool, message: string} $fields
     * @return array{status: 'issued', expiresAt: ?int}|array{status: 'extension_requested', reference: string}
     * @throws LicensingRequestException
     */
    public function send(array $fields): array {
        if (!$fields['extension'] && $this->blockedByPaidKey()) {
            throw new LicensingRequestException('licensed');
        }

        $answer = $this->portal->post(
            $this->portal->url(self::REQUEST_URL, self::CFG_URL_OVERRIDE),
            $this->buildPayload($fields),
        );
        $code = $answer['code'];
        $json = $answer['json'];

        if ($json !== null && $code === 409 && ($json['error'] ?? '') === 'trial_used') {
            throw new LicensingRequestException('trial_used');
        }

        if ($json !== null && in_array($code, [200, 201], true)) {
            $status = $json['status'] ?? '';
            if ($status === 'issued' && is_string($json['jwt'] ?? null) && !$fields['extension']) {
                return $this->install($json['jwt']);
            }
            if ($status === 'extension_requested' && is_string($json['reference'] ?? null) && $fields['extension']) {
                return ['status' => 'extension_requested', 'reference' => substr($json['reference'], 0, 32)];
            }
        }

        $failure = LicensingPortalClient::failureFor($answer);
        if ($failure->getReason() === 'rejected') {
            $this->logger->warning('[TeamHub][TrialRequestService] The licensing service refused a field the app accepted: ' . $failure->getMessage(), [
                'app' => Application::APP_ID,
            ]);
        }
        throw $failure;
    }

    /**
     * Verify and store the issued key. A key this instance refuses (wrong
     * UUID, bad signature) is a fault on our side; the trial is still spent
     * in the registry, so the administrator is told to contact us.
     *
     * @return array{status: 'issued', expiresAt: ?int}
     */
    private function install(string $jwt): array {
        try {
            $claims = $this->licenseService->saveKey($jwt);
        } catch (\Throwable $e) {
            $this->logger->error('[TeamHub][TrialRequestService] The licensing service issued a trial key this instance does not accept: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);
            throw new LicensingRequestException('invalid_key', $e->getMessage());
        }
        return ['status' => 'issued', 'expiresAt' => isset($claims['exp']) ? (int)$claims['exp'] : null];
    }

    /**
     * What leaves the instance: the address, whether this is an extension
     * request and its comment, the UUID, the member count and two versions.
     *
     * @param array{email: string, extension: bool, message: string} $fields
     */
    public function buildPayload(array $fields): array {
        return [
            'email'       => $fields['email'],
            'extension'   => $fields['extension'],
            'message'     => $fields['message'],
            'uuid'        => $this->licenseService->getInstanceUuid(),
            'members'     => $this->telemetryService->countUniqueTeamMembers(),
            'app_version' => $this->config->getAppValue(Application::APP_ID, 'installed_version', 'unknown'),
            'nc_version'  => (string)$this->config->getSystemValue('version', 'unknown'),
        ];
    }
}
