<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Exception\LicensingRequestException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * The transport under the License tab's two requests to the licensing
 * service, the quote and the trial key (v4.14.12).
 *
 * One rule matters more than the rest, and it is why this is shared rather
 * than copied: **a status code is only believed when the body is the
 * receiver's own JSON.** A hosting error page or a proxy's refusal says
 * nothing about the request, and SeatReportService read one as "licence
 * revoked" before that rule existed (DESIGN §2.248). So `post()` hands back
 * the decoded body or null, and each caller maps codes only when there is one.
 *
 * No credential is sent: an unlicensed instance has no key, and it is the
 * instance most likely to ask. The receiver throttles per UUID and address.
 */
class LicensingPortalClient {

    private const TIMEOUT = 15;

    public function __construct(
        private IConfig        $config,
        private IClientService $clientService,
    ) {}

    /**
     * POST a JSON body. Returns the status and the decoded body, which is null
     * whenever the answer was not JSON.
     *
     * @return array{code: int, json: ?array}
     * @throws LicensingRequestException `unreachable` on a transport failure
     */
    public function post(string $url, array $payload): array {
        try {
            $response = $this->clientService->newClient()->post($url, [
                'timeout'     => self::TIMEOUT,
                // A refusal comes back as a response, not an exception, so the
                // receiver's error code can be read (§2.248).
                'http_errors' => false,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'        => json_encode($payload),
            ]);
            $code        = $response->getStatusCode();
            $contentType = (string)$response->getHeader('Content-Type');
            $body        = (string)$response->getBody();
        } catch (\Throwable $e) {
            throw new LicensingRequestException('unreachable', $e->getMessage());
        }

        $json = null;
        if (str_contains(strtolower($contentType), 'application/json')) {
            $decoded = json_decode($body, true);
            $json = is_array($decoded) ? $decoded : null;
        }
        return ['code' => $code, 'json' => $json];
    }

    /**
     * The URL for one endpoint, or its https override from appconfig. The
     * override is a test seam for a staging portal, not a customer setting.
     */
    public function url(string $default, string $overrideKey): string {
        $override = trim($this->config->getAppValue(Application::APP_ID, $overrideKey, ''));
        if ($override !== '' && str_starts_with($override, 'https://')) {
            return $override;
        }
        return $default;
    }

    /**
     * The failure every caller shares, for an answer that was not a success:
     * not the receiver's JSON, a throttle, a refused field, or a server error.
     *
     * @param array{code: int, json: ?array} $answer
     */
    public static function failureFor(array $answer): LicensingRequestException {
        $code = $answer['code'];
        $json = $answer['json'];
        if ($json === null) {
            return new LicensingRequestException('unavailable', 'HTTP ' . $code . ' without a JSON body');
        }
        if ($code === 429) {
            return new LicensingRequestException('rate_limited');
        }
        if ($code === 422 || $code === 400) {
            $field = is_string($json['error'] ?? null) ? $json['error'] : 'unknown';
            return new LicensingRequestException('rejected', $field);
        }
        return new LicensingRequestException('unavailable', 'HTTP ' . $code);
    }
}
