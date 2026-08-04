<?php
declare(strict_types=1);

namespace OCA\TeamHub\MyWork;

/**
 * The standardized My Work action vocabulary (v4.5.21).
 *
 * Every action a work item can offer is one of these strings, whatever the
 * provider behind it. The frontend renders labels and icons from this list
 * alone and never learns a provider-specific verb — that is what makes a new
 * provider free at the UI layer.
 *
 * Two families:
 *
 *  - **Source actions** (open, complete, approve, reject, request_changes,
 *    comment, delegate) are executed BY the provider against the source app.
 *    A provider that does not declare one in its capabilities can never be
 *    asked to perform it.
 *  - **TeamHub-native actions** (snooze, unsnooze) are executed by TeamHub
 *    against its own `teamhub_mywork_state` table and never touch the source
 *    app. Every provider gets them for free.
 *  - **Navigation actions** (join, agenda — v4.5.25) are executed by nobody.
 *    A provider declares one and supplies the URL in metadata; the frontend
 *    opens it and never posts. They exist because "Action required" on a row
 *    with no button is a complaint, not a queue — a meeting starting in ten
 *    minutes should offer the call, not just an explanation.
 *
 * `unsnooze` is not in the original specification's list. It is here because
 * "show snoozed items" is a required filter, and an item the user can see but
 * cannot un-snooze is a dead end.
 *
 * `follow` / `unfollow` existed from v4.5.21 and were removed in v4.5.40. See
 * MyWorkState's docblock for why: the pair could not be undone, so "stop
 * following" silently meant "never show me this again".
 */
final class ActionType {
    public const OPEN            = 'open';
    public const COMPLETE        = 'complete';
    public const APPROVE         = 'approve';
    public const REJECT          = 'reject';
    public const REQUEST_CHANGES = 'request_changes';
    public const COMMENT         = 'comment';
    public const DELEGATE        = 'delegate';
    public const SNOOZE          = 'snooze';
    public const UNSNOOZE        = 'unsnooze';
    /**
     * v4.5.42 — a decision proposer closing their own drafting phase.
     *
     * Not `COMPLETE`: that means "this task is done", and a finalized proposal
     * is the opposite — it has just become somebody else's turn. The queue row
     * would have read "Complete" for an action that hands the item to an
     * approver, which is the kind of label that teaches people to distrust the
     * buttons.
     */
    public const FINALIZE        = 'finalize';
    /** v4.5.25 — navigation only. See NAVIGATION below. */
    public const JOIN            = 'join';
    public const AGENDA          = 'agenda';

    public const ALL = [
        self::OPEN, self::COMPLETE, self::APPROVE, self::REJECT,
        self::REQUEST_CHANGES, self::COMMENT, self::DELEGATE,
        self::SNOOZE, self::UNSNOOZE, self::FINALIZE,
        self::JOIN, self::AGENDA,
    ];

    /** Handled by TeamHub itself; a provider never sees these. */
    public const NATIVE = [self::SNOOZE, self::UNSNOOZE];

    /**
     * Opened by the frontend from a URL in the item's metadata; never posted
     * to the action endpoint, so no provider implements them and there is
     * nothing for an administrator to restrict — they change nothing.
     */
    public const NAVIGATION = [self::JOIN, self::AGENDA];

    /**
     * Actions that change state in the source application. These are the ones
     * an administrator can restrict per provider, and the ones written to the
     * audit log.
     */
    public const SOURCE_MUTATING = [
        self::COMPLETE, self::APPROVE, self::REJECT, self::REQUEST_CHANGES,
        self::DELEGATE, self::FINALIZE,
    ];

    public static function isValid(string $action): bool {
        return in_array($action, self::ALL, true);
    }

    public static function isNative(string $action): bool {
        return in_array($action, self::NATIVE, true);
    }
}
