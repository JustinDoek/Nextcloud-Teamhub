<?php
declare(strict_types=1);

namespace OCA\TeamHub\Exception;

/**
 * A request from the License tab to the licensing service that did not end in
 * the result asked for (v4.14.12): a quote request or a trial-key request.
 *
 * Carries a short machine code rather than a sentence, because the sentence is
 * the administrator's and is written in their language by the License tab:
 *
 *  - `unreachable`  — no answer at all (DNS, TLS, no route, timeout). The
 *                     typical air-gapped install. The tab offers e-mail.
 *  - `rate_limited` — the service has had enough requests from this instance
 *                     for now.
 *  - `rejected`     — the service read the request and refused a field. The
 *                     app validates the same fields first, so this means the
 *                     two disagree and is worth a log line.
 *  - `unavailable`  — the service answered with something other than its own
 *                     JSON, or a 5xx. Not the administrator's to fix.
 *  - `trial_used`   — trial only: this instance UUID has had its trial. The
 *                     tab offers to ask for an extended one.
 *  - `licensed`     — trial only: a paid key is installed, and a trial key
 *                     would replace it. Refused before anything leaves.
 *  - `invalid_key`  — trial only: the service issued a key this instance does
 *                     not accept. Should never happen; logged when it does.
 */
class LicensingRequestException extends \RuntimeException {

    public function __construct(private string $reason, string $detail = '') {
        parent::__construct($detail !== '' ? $detail : $reason);
    }

    public function getReason(): string {
        return $this->reason;
    }
}
