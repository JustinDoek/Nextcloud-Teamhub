TeamHub — full app description
==============================

This is the long-form version of the Nextcloud App Store listing for TeamHub.
The listing itself carries only the headlines; everything below is the detail
behind them, including the limits TeamHub deliberately does not claim to
exceed.

TeamHub is a Nextcloud app. It runs inside your own instance, under AGPL-3.0.
Source and issue tracker: https://github.com/JustinDoek/Nextcloud-Teamhub
Website: https://teamhub.doekworks.eu


Why you would install it
------------------------

Your users are asking for Microsoft Teams. Your auditor is asking who approved
what, and where it is stored. TeamHub answers both from the Nextcloud you
already run.

A Nextcloud Team on its own is a membership list — a group you can share a
folder or a calendar with. TeamHub turns that list into a governed workspace.
Every team gets one page carrying a persistent message stream, decisions,
presence, an activity timeline and projects, sitting next to the Talk
conversation, team space, Calendar, Collective and Deck board that team
already uses.

Nothing is copied and no new silo appears. Deck stays the source of truth for
cards, Talk for conversations, Files for documents. TeamHub reads across them,
writes your actions back to them, and records what happened.


Control — workspaces come out the way you decided
-------------------------------------------------

Templates and policy profiles
    A team is created from a template that fixes its apps and modules, and
    carries a policy profile that fixes its settings. Classification is a
    property of the team, not a habit you hope people keep.

No side doors into the estate
    A team created in Contacts, Collectives or `occ` is not a TeamHub team and
    is not shown in TeamHub. A team created in TeamHub cannot be deleted from
    Nextcloud's own Teams page — the same mechanism Collectives uses.

Gates are server-side, not UI-side
    Every controller method carries its own membership and role check.
    `POST /api/v1/teams` refuses a caller who may not create teams even when
    the interface is bypassed entirely. "The frontend won't call this" is not
    treated as a security boundary anywhere in the codebase.

You decide who may create a team at all
    And when nobody may, the request goes to a service team you designate,
    where it is claimed, answered and closed on the record rather than in
    somebody's inbox.

External reach is one switch you own
    Email and federated invitations are enabled for the instance or they are
    not. The Compliance tab states which you chose, so the answer to "can a
    team admin invite someone outside this server?" is a screenshot, not an
    investigation.

Bulk rollout from CSV
    One row per team, each naming its template, policy, owner and members. The
    upload gives you a dry run first: every row is checked, conflicts and
    errors are shown with reasons, and nothing is created until you confirm.
    Imported teams come out identical to wizard-created ones and land with the
    right owner, not the admin who ran the import.

Restricted means hidden
    An action, link or option a role may not perform is not rendered for that
    role. It is not disabled, and it is not left to fail at the server. There
    is no greyed-out button to probe.


Evidence — what you can hand an auditor
---------------------------------------

Per-team audit log
    Membership, file, share, resource and configuration events, with actor,
    timestamp and before/after values. The service exposes append and bulk
    purge only — no code path updates or deletes an individual row, so a
    record cannot be silently rewritten before its retention window expires.
    Retention is yours to set between 7 and 3650 days, defaulting to 90. Any
    team exports as a ZIP of JSON.

It sees past its own edges
    An hourly job mirrors Nextcloud's own activity for Circles and Files into
    the same log, and snapshot-diffs the share table to surface share
    created / changed / deleted. Changes made outside TeamHub land in the same
    stream instead of being invisible.

ISO/IEC 27001:2022 control report
    The Compliance tab reports this instance's live state against the Annex A
    controls TeamHub can evidence, and prints it:

        A.5.3   Segregation of duties
        A.5.9   Inventory of information and other associated assets
        A.5.10  Acceptable use of information and other associated assets
        A.5.12  Classification of information
        A.5.13  Labelling of information
        A.5.15  Access control
        A.5.18  Access rights
        A.5.33  Protection of records
        A.5.34  Privacy and protection of PII
        A.8.15  Logging
        A.8.16  Monitoring activities

    The report also prints the controls nothing currently evidences. This is
    input for your Statement of Applicability — it is not a certification, and
    it does not claim to be one. A gap you can read beats a green tick you
    cannot check.

Drift detection, with its limits stated
    Teams carrying a policy profile are compared against the settings that
    profile defines, and differences are reported. Eight of the nine governed
    settings can also be changed in Contacts or the Teams app, which dispatch
    no event when they do. This check therefore detects a difference; it does
    not prevent one, and it cannot attribute it to an actor. Unclassified
    teams are not compared and are not counted. Better you read that here than
    discover it during an audit.

Decisions with no delete button
    Propose, discuss, finalise and approve, with per-category approvers, a
    reason recorded on every outcome, and linked tasks.

Sealed request records
    A completed service request is archived once, and its history is sealed
    with a SHA-256 chain; an endpoint reports whether the chain still
    verifies. There is one authoritative record and no second copy. Access is
    computed from live roles, so a participant who leaves the team, an agent
    taken off the desk and a deleted team each close the record by themselves.

Code integrity
    Shipped files are verified against a SHA-256 manifest generated at build
    time. Altered, missing and unexpected files are named. This is the
    signature of a tampered install or a half-finished upgrade.

Pseudonymised exports
    An archive export replaces Nextcloud account IDs with stable positional
    aliases, and the alias map is held only in memory and never written to the
    archive. We call that pseudonymisation rather than anonymisation, on
    purpose: free text such as message bodies and descriptions is not
    scrubbed, organisational structure is retained, and the result therefore
    remains personal data under the GDPR — with reduced linkability, not none.


Residency — nothing leaves your server
--------------------------------------

TeamHub runs entirely inside your own Nextcloud. There is no hosted version,
no external service, no callback to us and no sub-processor to add to your
register.

An instance with an active licence, trial or grace period transmits nothing at
all. Reporting is derived from licence state rather than from a toggle, so it
cannot be left on by accident.

An unlicensed Community instance sends one daily aggregate containing:

    - a randomly generated UUID stored in this instance's app config
    - the TeamHub and Nextcloud version numbers
    - counts: teams, users, unique team members, members, messages
    - which integrations are registered, and counts per built-in integration
    - whether the Presence module is on
    - the bare hostnames of custom link widgets, with counts

It contains no account IDs, no message bodies, no file names, no file
contents, no instance URL and no hostname of your server. Custom link URLs are
reduced to their bare hostname before aggregation — no paths, query strings,
ports, fragments or IP addresses. The complete payload is written to your own
log at DEBUG level, so you can read exactly what was sent rather than take
this paragraph on trust.


What the teams actually get
---------------------------

    Team home          One page per team, with a layout every member arranges
                       for themselves.
    Messages           Announcements, questions and polls in a persistent team
                       stream, pinned or threaded.
    Activity feed      Recent file, calendar, task and member changes across
                       every shared resource, in one view.
    Timeline           A zoomable canvas aggregating Deck cards, Calendar
                       events, Decisions and Messages, with admin-defined
                       milestones and connector lines.
    Presence           Every member publishes a working location per half-day,
                       collected into a team week grid and written to their own
                       Nextcloud Calendar.
    Advanced Projects  Four phases, a guided Project Compass, milestones bound
                       to Deck cards, per-workstream budget and time, and a
                       live project health widget.
    My Work            Everything waiting on you across every team, ordered by
                       urgency. Each row says why it is there, which team it
                       belongs to and who is waiting on whom, and carries the
                       whole workflow: which step it is on, who acted, what
                       comes next. Actions write straight back to the Deck
                       card, file, decision or poll.
    What's new         Activity aggregated across every team you belong to,
                       plus public posts from teams you are not in. Comment or
                       vote without leaving the page.
    File reviews       Request a review from the Files app, then track and
                       complete it in My Work.
    Service teams      A desk that answers requests from the rest of the
                       organisation: a catalogue of services, a queue, claiming
                       and reassignment, per-request conversation, and
                       statistics. A whole department joins by adding its
                       Nextcloud group to the team.
    App tabs           The team's Talk chat, team space, Calendar, Collective
                       and Deck board, one click away and opened inline.
    Custom tabs        Any URL as a per-team tab, and the apps a team does not
                       use switched off.
    Widgets            Upcoming events, open Deck tasks, Pages, presence and
                       activity snapshots, plus anything an integration adds.


For administrators
------------------

Team admins and owners control their own resources, members and permissions.
Nextcloud admins manage TeamHub from admin settings: org-wide rollout,
creation permissions, optional modules, the profile fields used to tell two
people of the same name apart, audit and archive tooling, licence state, and
the Compliance tab described above.


For developers
--------------

Other Nextcloud apps register their own sidebar widgets or sandboxed iframe
tabs into a team's home. Registration resolves through Nextcloud's DI
container and is called in-process — no HTTP round trip, and no call back into
the same instance over HTTP anywhere in the app.

    $teamHub->registerIntegration(
        appId:           'myapp',
        integrationType: 'widget',
        title:           'My Widget',
        description:     'Shows recent items from My App',
        icon:            'ChartBar',
        phpClass:        \OCA\MyApp\Integration\TeamHubWidget::class,
        calledInProcess: true,
    );

The full guide, including the menu-item (iframe tab) integration type, is in
developers.md in the repository.


Requirements
------------

    Nextcloud    33 - 35
    PHP          8.1 - 8.5
    Database     MySQL/MariaDB or PostgreSQL
    Required     The Teams (Circles) app, enabled

Database tables are created automatically on first enable. TeamHub ships in
six languages besides English — Dutch, German, French, Danish, Spanish and
Italian — and backend notifications and emails are sent in each recipient's
own language rather than the sender's.


Free to develop, licensed to run
--------------------------------

The Community version is a full development version, free under AGPL-3.0. Use
it, study it, improve it, and expect in-app feedback prompts plus the one
anonymous daily aggregate described under Residency above.

A production licence:

    - unlocks the gated modules — the personal layer, Advanced Projects, bulk
      creation and export, file reviews, OpenProject and Services
    - unlocks the compliance centre
    - turns the app quiet: no feedback prompts, no branding, no telemetry
    - gives you a direct line to the maintainer

Every instance can start one 30-day full trial from the License tab in admin
settings. A paid key has fourteen days of grace after expiry; a trial has
none.


TeamHub is a third-party app and is not affiliated with or endorsed by
Nextcloud GmbH.
