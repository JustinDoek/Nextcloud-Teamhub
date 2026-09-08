<?php
declare(strict_types=1);

namespace OCA\TeamHub\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    public function __construct(
        IFactory $l10nFactory,
        IURLGenerator $urlGenerator
    ) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'teamhub';
    }

    public function getName(): string {
        return $this->l10nFactory->get('teamhub')->t('TeamHub');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'teamhub') {
            throw new UnknownNotificationException('Unknown app');
        }

        switch ($notification->getSubject()) {
            case 'new_message':
                $params = $notification->getSubjectParameters();
                $authorName = $params['author']  ?? 'Someone';
                $teamName   = $params['team']    ?? 'a team';
                $subject    = $params['subject'] ?? '';

                // setRichSubject replaces {placeholder} tokens with the rich objects.
                // This is the correct NC API — $l->t() with {foo} syntax does NOT interpolate.
                $notification->setRichSubject(
                    'New message from {author} in {team}',
                    [
                        'author' => [
                            'type'  => 'user',
                            'id'    => $params['authorId'] ?? $authorName,
                            'name'  => $authorName,
                        ],
                        'team' => [
                            'type'  => 'highlight',
                            'id'    => $params['teamId'] ?? $teamName,
                            'name'  => $teamName,
                        ],
                    ]
                );

                // Fallback plain text for clients that don't render rich subjects
                $notification->setParsedSubject(
                    'New message from ' . $authorName . ' in ' . $teamName
                );

                // Show the message subject as the notification body
                if ($subject !== '') {
                    $notification->setRichMessage('{subject}', [
                        'subject' => ['type' => 'highlight', 'id' => 'subject', 'name' => $subject],
                    ]);
                    $notification->setParsedMessage($subject);
                }

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));

                // Link is set by MessageService with ?team= param — use it if present,
                // otherwise fall back to the app root.
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }

                return $notification;

            case 'join_request':
                $params        = $notification->getSubjectParameters();
                $requesterName = $params['requesterName'] ?? ($params['requestingUid'] ?? 'Someone');
                $teamName      = $params['teamName']      ?? 'a team';
                $teamId        = $params['teamId']        ?? '';

                $notification->setRichSubject(
                    '{requester} wants to join {team}',
                    [
                        'requester' => [
                            'type' => 'user',
                            'id'   => $params['requestingUid'] ?? $requesterName,
                            'name' => $requesterName,
                        ],
                        'team' => [
                            'type' => 'highlight',
                            'id'   => $teamId,
                            'name' => $teamName,
                        ],
                    ]
                );
                $notification->setParsedSubject(
                    $requesterName . ' wants to join ' . $teamName
                );
                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }
                return $notification;

            case 'owner_assigned':
                $params      = $notification->getSubjectParameters();
                $adminName   = $params['adminName']  ?? ($params['adminUid'] ?? 'An administrator');
                $teamName    = $params['teamName']   ?? 'a team';
                $teamId      = $params['teamId']     ?? '';

                $notification->setRichSubject(
                    '{admin} assigned you as owner of {team}',
                    [
                        'admin' => [
                            'type' => 'user',
                            'id'   => $params['adminUid'] ?? $adminName,
                            'name' => $adminName,
                        ],
                        'team' => [
                            'type' => 'highlight',
                            'id'   => $teamId,
                            'name' => $teamName,
                        ],
                    ]
                );
                $notification->setParsedSubject(
                    $adminName . ' assigned you as owner of ' . $teamName
                );
                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }
                return $notification;

            // v4.6.13 — the outcome of an extension request, back to whoever
            // asked. Both branches use the recipient's own language via
            // $languageCode, which is what INotifier::prepare exists for and
            // what SKILLS.md § Translation standards requires of backend
            // strings sent to a specific user.
            case 'expiry_request_approved':
            case 'expiry_request_denied': {
                $params    = $notification->getSubjectParameters();
                $l         = $this->l10nFactory->get('teamhub', $languageCode);
                $adminName = $params['adminName'] ?? ($params['adminUid'] ?? $l->t('An administrator'));
                $teamName  = $params['teamName']  ?? $l->t('a team');
                $teamId    = $params['teamId']    ?? '';
                $grantedOn = $params['grantedOn'] ?? '';
                $approved  = $notification->getSubject() === 'expiry_request_approved';

                // Rich parameters only — no sprintf placeholders mixed in.
                // `{date}` is a rich parameter for the same reason `{team}` is:
                // the whole string is a template the renderer fills, and a
                // half-sprintf/half-rich string is one substitution pass away
                // from rendering a literal "%s" at somebody.
                $richParams = [
                    'admin' => [
                        'type' => 'user',
                        'id'   => $params['adminUid'] ?? $adminName,
                        'name' => $adminName,
                    ],
                    'team' => [
                        'type' => 'highlight',
                        'id'   => $teamId,
                        'name' => $teamName,
                    ],
                ];
                if ($approved) {
                    $richParams['date'] = [
                        'type' => 'highlight',
                        'id'   => $grantedOn,
                        'name' => $grantedOn,
                    ];
                }

                $notification->setRichSubject(
                    $approved
                        ? $l->t('{admin} extended {team} until {date}')
                        : $l->t('{admin} declined to extend {team}'),
                    $richParams,
                );
                $notification->setParsedSubject($approved
                    ? $l->t('%1$s extended %2$s until %3$s', [$adminName, $teamName, $grantedOn])
                    : $l->t('%1$s declined to extend %2$s', [$adminName, $teamName]));

                // The admin's note is the whole value of a denial, so it
                // becomes the message body rather than being dropped.
                $note = (string)($params['note'] ?? '');
                if ($note !== '') {
                    $notification->setParsedMessage($note);
                }

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }
                return $notification;
            }

            case 'message_mention':
                $params     = $notification->getSubjectParameters();
                $authorName = $params['author']   ?? 'Someone';

                $notification->setRichSubject(
                    '{author} mentioned you in a message',
                    [
                        'author' => [
                            'type' => 'user',
                            'id'   => $params['authorId'] ?? $authorName,
                            'name' => $authorName,
                        ],
                    ]
                );
                $notification->setParsedSubject($authorName . ' mentioned you in a message');
                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }
                return $notification;

            // v4.8.7 (GitHub #95) — a comment landed on a thread this
            // recipient subscribed to. Rendered through $languageCode like the
            // expiry cases above, rather than in hard-coded English like the
            // older cases in this file: SKILLS.md § Translation standards
            // requires a backend string sent to a specific user to be looked
            // up in *that user's* language, and this is a new surface, so it
            // starts correct rather than inheriting the gap.
            case 'message_comment': {
                $params     = $notification->getSubjectParameters();
                $l          = $this->l10nFactory->get('teamhub', $languageCode);
                $authorName = $params['author'] ?? $l->t('Someone');
                $teamName   = $params['team']   ?? $l->t('a team');
                $subject    = (string)($params['subject'] ?? '');

                $richParams = [
                    'author' => [
                        'type' => 'user',
                        'id'   => $params['authorId'] ?? $authorName,
                        'name' => $authorName,
                    ],
                    'team' => [
                        'type' => 'highlight',
                        'id'   => $params['teamId'] ?? $teamName,
                        'name' => $teamName,
                    ],
                ];

                // `subject` is notnull on teamhub_messages, so the second
                // branch should be unreachable — it exists because a
                // notification that renders "commented on" with a blank space
                // after it is worse than one that names the team instead.
                if ($subject !== '') {
                    $richParams['message'] = [
                        'type' => 'highlight',
                        'id'   => (string)($params['messageId'] ?? $subject),
                        'name' => $subject,
                    ];
                    // TRANSLATORS: {author} is a person, {message} is the title of the team message they commented on
                    $notification->setRichSubject($l->t('{author} commented on {message}'), $richParams);
                    $notification->setParsedSubject(
                        $l->t('%1$s commented on %2$s', [$authorName, $subject]),
                    );
                    // The team is the context that makes the title mean
                    // something when two teams both have a "Weekly update".
                    $notification->setRichMessage('{team}', [
                        'team' => $richParams['team'],
                    ]);
                    $notification->setParsedMessage($teamName);
                } else {
                    // TRANSLATORS: shown when the team message has no title; {author} is a person, {team} is the team name
                    $notification->setRichSubject($l->t('{author} commented on a message in {team}'), $richParams);
                    $notification->setParsedSubject(
                        $l->t('%1$s commented on a message in %2$s', [$authorName, $teamName]),
                    );
                }

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                // MessageSubscriptionService sets a ?team=…&message=… link so
                // the bell lands on the thread rather than the team's home.
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }
                return $notification;
            }

            // v4.8.18 — file reviews. Four subjects, all rendered through
            // $languageCode like `message_comment` above rather than in
            // hard-coded English like the oldest cases in this file.
            //
            // `file_review_closed` is the one that carries weight beyond
            // courtesy: a reviewer who never completed loses the item from
            // My Work the moment the requester closes, and this is the only
            // thing that tells them it happened. See FileReviewWorkProvider.
            case 'file_review_requested':
            case 'file_review_completed':
            case 'file_review_all_done':
            case 'file_review_closed': {
                $params    = $notification->getSubjectParameters();
                $l         = $this->l10nFactory->get('teamhub', $languageCode);
                $actorName = $params['actor'] ?? $l->t('Someone');
                $fileName  = (string)($params['file'] ?? '');
                $teamName  = $params['team'] ?? $l->t('a team');

                // A review always has a file name — it is snapshotted on the
                // row precisely so it survives the file being deleted — but a
                // notification that renders an empty gap would be worse than
                // one that says "a file", so the fallback stays.
                if ($fileName === '') {
                    $fileName = $l->t('a file');
                }

                $richParams = [
                    'actor' => [
                        'type' => 'user',
                        'id'   => $params['actorId'] ?? $actorName,
                        'name' => $actorName,
                    ],
                    'file' => [
                        'type' => 'highlight',
                        'id'   => (string)($params['fileId'] ?? $fileName),
                        'name' => $fileName,
                    ],
                    'team' => [
                        'type' => 'highlight',
                        'id'   => $params['teamId'] ?? $teamName,
                        'name' => $teamName,
                    ],
                ];

                switch ($notification->getSubject()) {
                    case 'file_review_requested':
                        // TRANSLATORS: {actor} is a person, {file} is a file name — they want you to review that file
                        $notification->setRichSubject($l->t('{actor} asked you to review {file}'), $richParams);
                        $notification->setParsedSubject(
                            $l->t('%1$s asked you to review %2$s', [$actorName, $fileName]),
                        );
                        break;

                    case 'file_review_completed':
                        // TRANSLATORS: {actor} is a person who finished reviewing {file}, a file name
                        $notification->setRichSubject($l->t('{actor} completed the review of {file}'), $richParams);
                        $notification->setParsedSubject(
                            $l->t('%1$s completed the review of %2$s', [$actorName, $fileName]),
                        );
                        break;

                    case 'file_review_all_done':
                        // TRANSLATORS: every reviewer has now finished; {file} is a file name
                        $notification->setRichSubject($l->t('All reviews of {file} are complete'), $richParams);
                        $notification->setParsedSubject(
                            $l->t('All reviews of %s are complete', [$fileName]),
                        );
                        break;

                    default:
                        // TRANSLATORS: {actor} is the person who asked for the review and has now ended it; {file} is a file name
                        $notification->setRichSubject($l->t('{actor} closed the review of {file}'), $richParams);
                        $notification->setParsedSubject(
                            $l->t('%1$s closed the review of %2$s', [$actorName, $fileName]),
                        );
                        break;
                }

                // The team is the context that makes a file name mean
                // something when two teams both have a "Budget.xlsx".
                $notification->setRichMessage('{team}', ['team' => $richParams['team']]);
                $notification->setParsedMessage($teamName);

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                // FileReviewService sets a ?mywork link so the bell lands on
                // the queue the item lives in.
                if (!$notification->getLink()) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'teamhub.page.index'
                    ));
                }
                return $notification;
            }

            case 'license_over_seats':
                // Fired by LicenseExpiryNotificationJob when unique-team-member
                // count first crosses the licensed seat cap. Same notification
                // pushes on every transition from under → over so extending the
                // license doesn't require re-firing manually.
                $params = $notification->getSubjectParameters();
                $used   = (int)($params['seatsUsed'] ?? 0);
                $cap    = (int)($params['seatCap']   ?? 0);
                $lockAt = (int)($params['seatLockAt'] ?? (int)ceil($cap * 1.2));

                $notification->setRichSubject(
                    'TeamHub license is over its seat cap ({used} of {cap})',
                    [
                        'used' => ['type' => 'highlight', 'id' => 'used', 'name' => (string)$used],
                        'cap'  => ['type' => 'highlight', 'id' => 'cap',  'name' => (string)$cap],
                    ]
                );
                $notification->setParsedSubject(
                    'TeamHub license is over its seat cap (' . $used . ' of ' . $cap . ')'
                );
                $notification->setRichMessage(
                    'Upgrade the license or reduce unique team members. Advanced-team creation and writes lock at ' . $lockAt . ' users.',
                    []
                );
                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                try {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'settings.AdminSettings.index',
                        ['section' => 'teamhub']
                    ));
                } catch (\Throwable $e) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute('teamhub.page.index'));
                }
                return $notification;

            case 'license_expiring_trial':
            case 'license_expiring_paid':
                // Fired by LicenseExpiryNotificationJob. Both variants share
                // the same shape and target audience; the copy differs so
                // "your paid entitlement ends in N days" reads distinctly
                // from "your trial ends in N days".
                $params = $notification->getSubjectParameters();
                $days   = (int)($params['daysRemaining'] ?? 0);
                $isTrial = $notification->getSubject() === 'license_expiring_trial';

                $rich = $isTrial
                    ? 'Your TeamHub trial ends in {days} days'
                    : 'Your TeamHub license paid entitlement ends in {days} days';
                $plain = $isTrial
                    ? 'Your TeamHub trial ends in ' . $days . ' days'
                    : 'Your TeamHub license paid entitlement ends in ' . $days . ' days';

                $notification->setRichSubject($rich, [
                    'days' => [
                        'type' => 'highlight',
                        'id'   => (string)$days,
                        'name' => (string)$days,
                    ],
                ]);
                $notification->setParsedSubject($plain);

                $notification->setRichMessage(
                    $isTrial
                        ? 'Install a paid license from your TeamHub admin panel to keep using Advanced features after the trial ends.'
                        : 'Renew your TeamHub license from your admin panel — after the paid entitlement ends the license enters its grace period, then Advanced features lock.',
                    []
                );

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                // Deep-link to the License tab of TeamHub admin settings.
                // Falls back to the app root if the settings route isn't
                // resolvable — the licensing tab is discoverable from there.
                try {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                        'settings.AdminSettings.index',
                        ['section' => 'teamhub']
                    ));
                } catch (\Throwable $e) {
                    $notification->setLink($this->urlGenerator->linkToRouteAbsolute('teamhub.page.index'));
                }
                return $notification;

            default:
                throw new UnknownNotificationException('Unknown subject');
        }
    }
}
