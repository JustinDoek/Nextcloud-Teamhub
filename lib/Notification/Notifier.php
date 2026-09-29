<?php
declare(strict_types=1);

namespace OCA\TeamHub\Notification;

use OCA\TeamHub\Workflow\WorkflowDefinitionRegistry;
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
        IURLGenerator $urlGenerator,
        // v4.10.14 — workflow notifications name the workflow and its step in
        // the recipient's language through the definition.
        private WorkflowDefinitionRegistry $workflowDefinitions,
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

            // v4.10.1 — the team-space reconcile pass talking to Nextcloud
            // administrators (TeamSpaceReconcileService). Three subjects, one
            // shape: the team highlighted, a sentence of what happened, and a
            // link that depends on the licence — My Work carries the item with
            // the procedure on a licensed instance, the docs page carries it
            // everywhere else. Per-recipient language, like every case here
            // that reaches a person.
            case 'teamspace_shared_folder':
            case 'teamspace_shares_removed':
            case 'teamspace_conflict':
            case 'teamspace_task_assigned':
            case 'teamspace_task_completed': {
                $params   = $notification->getSubjectParameters();
                $l        = $this->l10nFactory->get('teamhub', $languageCode);
                $teamName = (string)($params['teamName'] ?? $l->t('a team'));
                $teamId   = (string)($params['teamId'] ?? '');
                $team     = ['type' => 'highlight', 'id' => $teamId !== '' ? $teamId : $teamName, 'name' => $teamName];

                switch ($notification->getSubject()) {
                    case 'teamspace_shared_folder':
                        $shared = (string)($params['sharedFolder'] ?? '');
                        $space  = (string)($params['spaceName'] ?? '');
                        $notification->setRichSubject($l->t('{team} still uses a shared folder'), ['team' => $team]);
                        $notification->setParsedSubject($l->t('%s still uses a shared folder', [$teamName]));
                        $notification->setParsedMessage($l->t(
                            'Nextcloud 35 gives every team a team space. TeamHub created the team space "%1$s" next to the shared folder "%2$s". Ask the team owner to move the files into the team space, connect it, and disconnect the shared folder.',
                            [$space, $shared],
                        ));
                        break;
                    case 'teamspace_shares_removed':
                        $folder  = (string)($params['folderName'] ?? '');
                        $removed = (string)($params['removed'] ?? '');
                        $count   = (int)($params['count'] ?? 0);
                        $notification->setRichSubject($l->t('The team folder of {team} is now a team space'), ['team' => $team]);
                        $notification->setParsedSubject($l->t('The team folder of %s is now a team space', [$teamName]));
                        $notification->setParsedMessage($l->t(
                            'A team space belongs to its team only. Access to "%1$s" was removed for %2$s: %3$s.',
                            [$folder, $l->n('%n group or team', '%n groups or teams', $count), $removed],
                        ));
                        break;
                    case 'teamspace_task_assigned': {
                        // To the team owner: a Nextcloud administrator handed
                        // them the move. The optional note is the body.
                        $adminName = (string)($params['adminName'] ?? $l->t('An administrator'));
                        $adminUid  = (string)($params['adminUid'] ?? $adminName);
                        $note      = (string)($params['note'] ?? '');
                        $notification->setRichSubject($l->t('{admin} asks you to move {team} into its team space'), [
                            'admin' => ['type' => 'user', 'id' => $adminUid, 'name' => $adminName],
                            'team'  => $team,
                        ]);
                        $notification->setParsedSubject($l->t('%1$s asks you to move %2$s into its team space', [$adminName, $teamName]));
                        $notification->setParsedMessage($note !== ''
                            ? $note
                            : $l->t('The steps are on the task in My Work → Team admin. Press Complete there when the files are moved and the shared folder is disconnected.'));
                        break;
                    }
                    case 'teamspace_task_completed': {
                        $ownerName = (string)($params['ownerName'] ?? $l->t('The team owner'));
                        $ownerUid  = (string)($params['ownerUid'] ?? $ownerName);
                        $notification->setRichSubject($l->t('{owner} moved {team} into its team space'), [
                            'owner' => ['type' => 'user', 'id' => $ownerUid, 'name' => $ownerName],
                            'team'  => $team,
                        ]);
                        $notification->setParsedSubject($l->t('%1$s moved %2$s into its team space', [$ownerName, $teamName]));
                        $notification->setParsedMessage($l->t('The task was reported done. Check the team and close the row in My Work → Administration.'));
                        break;
                    }
                    default:
                        $space  = (string)($params['spaceName'] ?? '');
                        $folder = (string)($params['folderName'] ?? '');
                        $notification->setRichSubject($l->t('{team} has two folders with content'), ['team' => $team]);
                        $notification->setParsedSubject($l->t('%s has two folders with content', [$teamName]));
                        $notification->setParsedMessage($l->t(
                            'The team space "%1$s" and the team folder "%2$s" both contain files, so TeamHub did not merge them. Move the files into one of them and disconnect or delete the other.',
                            [$space, $folder],
                        ));
                        break;
                }

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
                $licensed = (int)($params['licensed'] ?? 0) === 1;
                $notification->setLink($licensed
                    ? $this->urlGenerator->linkToRouteAbsolute('teamhub.page.index') . '?mywork'
                    : \OCA\TeamHub\Service\TeamSpaceReconcileService::DOCS_URL);
                return $notification;
            }

            // v4.10.50 — teams made outside TeamHub (TeamAdoptionService). The
            // notification is the fallback, never the workflow: it is sent
            // only where no request carries the answer — to the
            // administrators when the grid is the only place (no licence),
            // and to the owner when their team was accepted by itself or
            // decided in the grid without a request.
            case 'team_adoption_pending':
            case 'team_adoption_accepted':
            case 'team_adoption_declined': {
                $params   = $notification->getSubjectParameters();
                $l        = $this->l10nFactory->get('teamhub', $languageCode);
                $teamName = (string)($params['teamName'] ?? '');
                if ($teamName === '') {
                    $teamName = $l->t('a team');
                }
                $team = ['type' => 'highlight', 'id' => $notification->getObjectId(), 'name' => $teamName];

                switch ($notification->getSubject()) {
                    case 'team_adoption_pending':
                        // TRANSLATORS: notification to Nextcloud administrators; {team} is a team somebody made outside TeamHub
                        $notification->setRichSubject($l->t('{team} was made outside TeamHub'), ['team' => $team]);
                        $notification->setParsedSubject($l->t('%s was made outside TeamHub', [$teamName]));
                        $notification->setParsedMessage($l->t('Accept it with a template and a policy, or decline it, in Admin → TeamHub → Team creation.'));
                        try {
                            $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                                'settings.AdminSettings.index',
                                ['section' => 'teamhub']
                            ) . '#team-adoption');
                        } catch (\Throwable $e) {
                            $notification->setLink($this->urlGenerator->linkToRouteAbsolute('teamhub.page.index'));
                        }
                        break;
                    case 'team_adoption_accepted':
                        // TRANSLATORS: notification to a team's owner; {team} is their team, now shown in TeamHub
                        $notification->setRichSubject($l->t('{team} is now in TeamHub'), ['team' => $team]);
                        $notification->setParsedSubject($l->t('%s is now in TeamHub', [$teamName]));
                        $notification->setLink($this->urlGenerator->linkToRouteAbsolute('teamhub.page.index')
                            . '?team=' . rawurlencode($notification->getObjectId()));
                        break;
                    default:
                        $reason = (string)($params['reason'] ?? '');
                        // TRANSLATORS: notification to a team's owner; {team} is their team, which will not be shown in TeamHub
                        $notification->setRichSubject($l->t('{team} was not added to TeamHub'), ['team' => $team]);
                        $notification->setParsedSubject($l->t('%s was not added to TeamHub', [$teamName]));
                        if ($reason !== '') {
                            $notification->setParsedMessage($reason);
                        }
                        break;
                }

                $notification->setIcon($this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->imagePath('teamhub', 'app.svg')
                ));
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

            // v4.10.14 — WorkflowHub. One shape for the five subjects: the
            // workflow's title and the step's label come from the definition
            // in the recipient's language; the note or reason is the body;
            // the link is the app (no workflow surface exists yet). Per
            // recipient language, like every case here that reaches a person.
            case 'workflow_step_available':
            case 'workflow_status_requested':
            case 'workflow_information_requested':
            case 'workflow_information_provided':
            case 'workflow_ended':
            // v4.10.20 - a service agent handed a request to this person.
            case 'workflow_step_assigned':
            // v4.11.0 - a message on a service request.
            case 'workflow_message':
                return $this->prepareWorkflow($notification, $languageCode);

            default:
                throw new UnknownNotificationException('Unknown subject');
        }
    }

    private function prepareWorkflow(INotification $notification, string $languageCode): INotification {
        $params     = $notification->getSubjectParameters();
        $l          = $this->l10nFactory->get('teamhub', $languageCode);
        $key        = (string)($params['definitionKey'] ?? '');
        $data       = is_array($params['data'] ?? null) ? $params['data'] : [];
        $definition = $key !== '' ? $this->workflowDefinitions->get($key) : null;
        $title      = $definition !== null ? $definition->getTitle($l, $data) : $key;
        $stepKey    = (string)($params['stepKey'] ?? '');
        $stepLabel  = ($definition !== null && $stepKey !== '' ? $definition->getStepLabel($l, $stepKey) : null)
            ?? (string)($params['stepLabel'] ?? $stepKey);
        $actorUid   = (string)($params['actorUid'] ?? '');
        $actorName  = (string)($params['actorName'] ?? $actorUid);
        $note       = trim((string)($params['note'] ?? ''));
        $workflow   = ['type' => 'highlight', 'id' => (string)($params['instanceId'] ?? $title), 'name' => $title];
        $actor      = ['type' => 'user', 'id' => $actorUid !== '' ? $actorUid : $actorName, 'name' => $actorName];

        switch ($notification->getSubject()) {
            case 'workflow_step_available':
                $notification->setRichSubject($l->t('{workflow}: the step "{step}" is waiting for you'), [
                    'workflow' => $workflow,
                    'step'     => ['type' => 'highlight', 'id' => $stepKey, 'name' => $stepLabel],
                ]);
                $notification->setParsedSubject($l->t('%1$s: the step "%2$s" is waiting for you', [$title, $stepLabel]));
                if ($actorName !== '') {
                    $notification->setParsedMessage($l->t('Handed over by %s.', [$actorName]));
                }
                break;
            case 'workflow_step_assigned':
                // TRANSLATORS: {actor} is the agent who handed the request over, {workflow} the request's title
                $notification->setRichSubject($l->t('{actor} assigned {workflow} to you'), ['actor' => $actor, 'workflow' => $workflow]);
                $notification->setParsedSubject($l->t('%1$s assigned %2$s to you', [$actorName, $title]));
                $notification->setParsedMessage($l->t('The step "%s" is yours now.', [$stepLabel]));
                break;
            case 'workflow_status_requested':
                $notification->setRichSubject($l->t('{actor} asks for a status update on {workflow}'), ['actor' => $actor, 'workflow' => $workflow]);
                $notification->setParsedSubject($l->t('%1$s asks for a status update on %2$s', [$actorName, $title]));
                $notification->setParsedMessage($note !== '' ? $note : $l->t('The step "%s" is waiting for you.', [$stepLabel]));
                break;
            case 'workflow_information_requested':
                $notification->setRichSubject($l->t('{actor} needs information for {workflow}'), ['actor' => $actor, 'workflow' => $workflow]);
                $notification->setParsedSubject($l->t('%1$s needs information for %2$s', [$actorName, $title]));
                $notification->setParsedMessage($note);
                break;
            case 'workflow_information_provided':
                $notification->setRichSubject($l->t('{actor} answered on {workflow}'), ['actor' => $actor, 'workflow' => $workflow]);
                $notification->setParsedSubject($l->t('%1$s answered on %2$s', [$actorName, $title]));
                $notification->setParsedMessage($note);
                break;
            case 'workflow_message':
                // TRANSLATORS: {actor} wrote a message on the service request {workflow}
                $notification->setRichSubject($l->t('{actor} sent a message on {workflow}'), ['actor' => $actor, 'workflow' => $workflow]);
                $notification->setParsedSubject($l->t('%1$s sent a message on %2$s', [$actorName, $title]));
                $notification->setParsedMessage($note);
                break;
            default: {
                $outcome = (string)($params['outcome'] ?? '');
                switch ($outcome) {
                    case 'completed':
                        $rich = $l->t('{workflow} was completed');
                        $flat = $l->t('%s was completed', [$title]);
                        break;
                    case 'rejected':
                        $rich = $l->t('{workflow} was rejected by {actor}');
                        $flat = $l->t('%1$s was rejected by %2$s', [$title, $actorName]);
                        break;
                    default:
                        $rich = $l->t('{workflow} was cancelled by {actor}');
                        $flat = $l->t('%1$s was cancelled by %2$s', [$title, $actorName]);
                        break;
                }
                $notification->setRichSubject($rich, ['workflow' => $workflow, 'actor' => $actor]);
                $notification->setParsedSubject($flat);
                if ($note !== '') {
                    $notification->setParsedMessage($note);
                }
                break;
            }
        }

        $notification->setIcon($this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('teamhub', 'app.svg')
        ));
        $notification->setLink($this->urlGenerator->linkToRouteAbsolute('teamhub.page.index'));
        return $notification;
    }
}
