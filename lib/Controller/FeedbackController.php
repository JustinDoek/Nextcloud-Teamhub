<?php
declare(strict_types=1);

namespace OCA\TeamHub\Controller;

use OCA\TeamHub\Service\FeedbackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class FeedbackController extends Controller {

    private const ALLOWED_TYPES = ['bug', 'feature', 'other'];
    private const MAX_SUBJECT_LEN = 200;
    private const MAX_BODY_LEN = 5000;
    private const MAX_CONTACT_LEN = 254; // RFC 5321 max email length

    public function __construct(
        string $appName,
        IRequest $request,
        private FeedbackService $feedbackService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Submit user feedback or a feature request.
     *
     * POST /api/v1/feedback
     *
     * Body (JSON):
     *   type    string  required  'bug' | 'feature' | 'other'
     *   subject string  required  max 200 chars
     *   body    string  required  max 5000 chars
     *   contact string  optional  max 254 chars, must look like an email if present
     */
    #[NoAdminRequired]
    public function submit(): JSONResponse {
        $type    = trim((string) $this->request->getParam('type', ''));
        $subject = trim((string) $this->request->getParam('subject', ''));
        $body    = trim((string) $this->request->getParam('body', ''));
        $contact = trim((string) $this->request->getParam('contact', ''));

        error_log('[TeamHub][FeedbackController] submit: type=' . $type . ', subjectLen=' . strlen($subject));

        // --- Validation -------------------------------------------------------

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            error_log('[TeamHub][FeedbackController] invalid type: ' . $type);
            return new JSONResponse(
                ['error' => 'Invalid feedback type.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        if ($subject === '') {
            return new JSONResponse(
                ['error' => 'Subject is required.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        if (strlen($subject) > self::MAX_SUBJECT_LEN) {
            return new JSONResponse(
                ['error' => 'Subject must not exceed ' . self::MAX_SUBJECT_LEN . ' characters.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        if ($body === '') {
            return new JSONResponse(
                ['error' => 'Description is required.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        if (strlen($body) > self::MAX_BODY_LEN) {
            return new JSONResponse(
                ['error' => 'Description must not exceed ' . self::MAX_BODY_LEN . ' characters.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        if ($contact !== '') {
            if (strlen($contact) > self::MAX_CONTACT_LEN) {
                return new JSONResponse(
                    ['error' => 'Contact address is too long.'],
                    Http::STATUS_BAD_REQUEST,
                );
            }
            // Basic email format check — FILTER_VALIDATE_EMAIL is the NC-safe approach.
            if (filter_var($contact, FILTER_VALIDATE_EMAIL) === false) {
                return new JSONResponse(
                    ['error' => 'Contact address does not appear to be a valid email.'],
                    Http::STATUS_BAD_REQUEST,
                );
            }
        }

        // --- Dispatch --------------------------------------------------------

        try {
            $this->feedbackService->submit($type, $subject, $body, $contact);
        } catch (\RuntimeException $e) {
            error_log('[TeamHub][FeedbackController] send failed: ' . $e->getMessage());
            return new JSONResponse(
                ['error' => 'Your feedback could not be sent. Please try again later.'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }

        return new JSONResponse(['success' => true]);
    }
}
