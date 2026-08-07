<?php
declare(strict_types=1);

return [
    'routes' => [
        // Main page route
        ['name' => 'page#index',    'url' => '/',                  'verb' => 'GET'],
        // Standalone iframe page — visual timeline
        ['name' => 'page#timeline', 'url' => '/timeline/{teamId}', 'verb' => 'GET'],

        // ----------------------------------------------------------------
        // Team routes
        // ----------------------------------------------------------------
        ['name' => 'team#listTeams',             'url' => '/api/v1/teams',                                    'verb' => 'GET'],
        ['name' => 'team#browseAllTeams',         'url' => '/api/v1/teams/browse',                            'verb' => 'GET'],
        ['name' => 'team#getTeam',                'url' => '/api/v1/teams/{teamId}',                          'verb' => 'GET'],
        ['name' => 'team#createTeam',             'url' => '/api/v1/teams',                                    'verb' => 'POST'],
        ['name' => 'team#updateTeam',             'url' => '/api/v1/teams/{teamId}',                          'verb' => 'PUT'],
        ['name' => 'team#deleteTeam',             'url' => '/api/v1/teams/{teamId}',                          'verb' => 'DELETE'],
        ['name' => 'team#transferOwner',          'url' => '/api/v1/teams/{teamId}/transfer-owner',           'verb' => 'POST'],

        // Admin settings
        ['name' => 'team#getAdminSettings',       'url' => '/api/v1/admin/settings',                         'verb' => 'GET'],
        ['name' => 'team#saveAdminSettings',      'url' => '/api/v1/admin/settings',                         'verb' => 'POST'],
        ['name' => 'team#intravoxDiagnostic',     'url' => '/api/v1/admin/intravox-diagnostic',              'verb' => 'GET'],
        ['name' => 'team#collectivesDiagnostic',  'url' => '/api/v1/admin/collectives-diagnostic',           'verb' => 'GET'],
        ['name' => 'team#deckDiagnostic',         'url' => '/api/v1/admin/deck-diagnostic',                  'verb' => 'GET'],
        ['name' => 'team#searchAdminGroups',      'url' => '/api/v1/admin/groups/search',                    'verb' => 'GET'],
        ['name' => 'team#getAllowedInviteTypes',  'url' => '/api/v1/invite-types',                            'verb' => 'GET'],

        // Team members
        ['name' => 'team#getTeamMembers',         'url' => '/api/v1/teams/{teamId}/members',                 'verb' => 'GET'],
        ['name' => 'team#getAllEffectiveMembers',   'url' => '/api/v1/teams/{teamId}/members/all',             'verb' => 'GET'],
        ['name' => 'team#getMembersForManage',     'url' => '/api/v1/teams/{teamId}/members/manage',          'verb' => 'GET'],
        ['name' => 'team#removeMember',           'url' => '/api/v1/teams/{teamId}/members/{userId}',        'verb' => 'DELETE'],
        ['name' => 'team#updateMemberLevel',      'url' => '/api/v1/teams/{teamId}/members/{userId}/level',  'verb' => 'PUT'],
        ['name' => 'team#getPendingRequests',     'url' => '/api/v1/teams/{teamId}/pending-requests',        'verb' => 'GET'],
        ['name' => 'team#approveRequest',         'url' => '/api/v1/teams/{teamId}/approve/{userId}',        'verb' => 'POST'],
        ['name' => 'team#rejectRequest',          'url' => '/api/v1/teams/{teamId}/reject/{userId}',         'verb' => 'POST'],

        // Team resources & activity
        ['name' => 'team#getTeamResources',       'url' => '/api/v1/teams/{teamId}/resources',               'verb' => 'GET'],

        // Tasks (NC Tasks app — VTODO objects in the team calendar)
        ['name' => 'team#getTeamTasks',           'url' => '/api/v1/teams/{teamId}/tasks',                   'verb' => 'GET'],
        ['name' => 'team#createTeamTask',         'url' => '/api/v1/teams/{teamId}/tasks',                   'verb' => 'POST'],
        ['name' => 'team#getTimeline',            'url' => '/api/v1/teams/{teamId}/timeline',                'verb' => 'GET'],
        ['name' => 'team#getTeamActivity',        'url' => '/api/v1/teams/{teamId}/activity',                'verb' => 'GET'],
        ['name' => 'team#getTeamCalendarEvents',  'url' => '/api/v1/teams/{teamId}/calendar/events',         'verb' => 'GET'],
        ['name' => 'team#createCalendarEvent',    'url' => '/api/v1/teams/{teamId}/calendar/events',         'verb' => 'POST'],
        ['name' => 'team#listRooms',              'url' => '/api/v1/teams/{teamId}/rooms',                   'verb' => 'GET'],
        ['name' => 'team#getCalendarEventsForWeek', 'url' => '/api/v1/teams/{teamId}/calendar/events/week',  'verb' => 'GET'],
        ['name' => 'team#deleteCalendarEvents',   'url' => '/api/v1/teams/{teamId}/calendar/events',         'verb' => 'DELETE'],

        // Files widgets — favourite files and recently modified files
        ['name' => 'team#getTeamFavoriteFiles',   'url' => '/api/v1/teams/{teamId}/files/favorites',         'verb' => 'GET'],
        ['name' => 'team#getTeamRecentFiles',      'url' => '/api/v1/teams/{teamId}/files/recent',            'verb' => 'GET'],
        // Shared files widget — files/folders shared directly with the team circle
        ['name' => 'team#getTeamSharedFiles',      'url' => '/api/v1/teams/{teamId}/files/shared',            'verb' => 'GET'],

        // Team apps (enable/disable per team)
        ['name' => 'team#getTeamApps',            'url' => '/api/v1/teams/{teamId}/apps',                    'verb' => 'GET'],
        ['name' => 'team#updateTeamApps',         'url' => '/api/v1/teams/{teamId}/apps',                    'verb' => 'PUT'],
        ['name' => 'team#deleteTeamResource',     'url' => '/api/v1/teams/{teamId}/resources/{app}',         'verb' => 'DELETE'],

        // Connect existing resources to a team (team admin required)
        ['name' => 'resource_connect#connect',     'url' => '/api/v1/teams/{teamId}/resources/{app}/connect', 'verb' => 'POST'],

        // Resource state — pending/ignored management (team admin required)
        ['name' => 'resource_state#getPanelData',    'url' => '/api/v1/teams/{teamId}/resources/panel',                          'verb' => 'GET'],
        ['name' => 'resource_state#acceptResource',  'url' => '/api/v1/teams/{teamId}/resources/{app}/{resourceId}/accept',       'verb' => 'POST'],
        ['name' => 'resource_state#ignoreResource',  'url' => '/api/v1/teams/{teamId}/resources/{app}/{resourceId}/ignore',       'verb' => 'POST'],
        ['name' => 'resource_state#unignoreResource','url' => '/api/v1/teams/{teamId}/resources/{app}/{resourceId}/unignore',     'verb' => 'POST'],
        ['name' => 'resource_state#dismissResource', 'url' => '/api/v1/teams/{teamId}/resources/{app}/{resourceId}/dismiss',     'verb' => 'POST'],
        ['name' => 'resource_state#removeAccess',    'url' => '/api/v1/teams/{teamId}/resources/{app}/{resourceId}/remove',       'verb' => 'DELETE'],
        ['name' => 'resource_state#deleteResource',  'url' => '/api/v1/teams/{teamId}/resources/{app}/{resourceId}/delete',       'verb' => 'DELETE'],

        // Resource pickers — list resources owned by current user (any authenticated user)
        ['name' => 'picker#listCalendars',         'url' => '/api/v1/pickers/calendar',                       'verb' => 'GET'],
        ['name' => 'picker#listDeckBoards',        'url' => '/api/v1/pickers/deck',                           'verb' => 'GET'],
        ['name' => 'picker#listTalkRooms',         'url' => '/api/v1/pickers/talk',                           'verb' => 'GET'],
        ['name' => 'picker#listFileFolders',       'url' => '/api/v1/pickers/files',                          'verb' => 'GET'],
        ['name' => 'team#createIntravoxPage',     'url' => '/api/v1/teams/{teamId}/intravox/page',           'verb' => 'POST'],
        ['name' => 'team#deleteIntravoxPage',     'url' => '/api/v1/teams/{teamId}/intravox/page',           'verb' => 'DELETE'],
        ['name' => 'team#getIntravoxSubPages',    'url' => '/api/v1/teams/{teamId}/intravox/subpages',       'verb' => 'GET'],
        ['name' => 'team#getIntravoxTeamPage',    'url' => '/api/v1/teams/{teamId}/intravox/team-page',      'verb' => 'GET'],
        ['name' => 'team#invalidateIntravoxCache', 'url' => '/api/v1/teams/{teamId}/intravox/subpages/cache', 'verb' => 'DELETE'],

        // Collectives (v4.3.3) — parallel shape to Intravox above, but talks
        // to \OCA\Collectives\Service\CollectiveService + PageService in
        // process. See lib/Service/CollectivesService.php for the full
        // dispatch story (toggle-on auto-create, toggle-off archive-policy
        // routing, and the two currently-deferred pieces).
        ['name' => 'team#getCollectivesConfig',    'url' => '/api/v1/teams/{teamId}/collectives/config',           'verb' => 'GET'],
        ['name' => 'team#saveCollectivesConfig',   'url' => '/api/v1/teams/{teamId}/collectives/config',           'verb' => 'PUT'],
        ['name' => 'team#getCollectivesTeamRow',   'url' => '/api/v1/teams/{teamId}/collectives/team-collective',  'verb' => 'GET'],
        ['name' => 'team#getCollectivesSubPages',  'url' => '/api/v1/teams/{teamId}/collectives/subpages',         'verb' => 'GET'],
        ['name' => 'team#invalidateCollectivesCache', 'url' => '/api/v1/teams/{teamId}/collectives/subpages/cache', 'verb' => 'DELETE'],
        ['name' => 'team#createCollectivesPage',   'url' => '/api/v1/teams/{teamId}/collectives/pages',            'verb' => 'POST'],

        // Team actions
        ['name' => 'team#requestJoinTeam',        'url' => '/api/v1/teams/{teamId}/join',                    'verb' => 'POST'],
        ['name' => 'team#leaveTeam',              'url' => '/api/v1/teams/{teamId}/leave',                   'verb' => 'POST'],
        ['name' => 'team#markTeamSeen',           'url' => '/api/v1/teams/{teamId}/seen',                    'verb' => 'POST'],
        ['name' => 'team#createTeamResources',    'url' => '/api/v1/teams/{teamId}/create-resources',        'verb' => 'POST'],
        ['name' => 'team#inviteMembers',          'url' => '/api/v1/teams/{teamId}/invite-members',          'verb' => 'POST'],

        // Team config
        ['name' => 'team#getTeamConfig',          'url' => '/api/v1/teams/{teamId}/config',                  'verb' => 'GET'],
        ['name' => 'team#updateTeamConfig',       'url' => '/api/v1/teams/{teamId}/config',                  'verb' => 'PUT'],
        ['name' => 'team#updateTeamDescription',  'url' => '/api/v1/teams/{teamId}/description',             'verb' => 'PUT'],

        // User
        ['name' => 'team#searchUsers',            'url' => '/api/v1/users/search',                           'verb' => 'GET'],
        ['name' => 'team#checkApps',              'url' => '/api/v1/apps/check',                             'verb' => 'GET'],
        ['name' => 'team#canCreateTeam',          'url' => '/api/v1/user/can-create-team',                   'verb' => 'GET'],

        // ----------------------------------------------------------------
        // Message stream routes
        // ----------------------------------------------------------------
        ['name' => 'message#listMessages',        'url' => '/api/v1/teams/{teamId}/messages',                'verb' => 'GET'],
        ['name' => 'message#createMessage',       'url' => '/api/v1/teams/{teamId}/messages',                'verb' => 'POST'],
        // Messages module — per-team enable flag (v3.104.6).
        // MUST come BEFORE the `{messageId}` catchall PUT/DELETE below —
        // otherwise `PUT /messages/config` matches `updateMessage` with
        // messageId='config' and 400s. Stored as app-config:
        // messages_enabled_<teamId> = "1" or "0". Default enabled.
        ['name' => 'team#getMessagesConfig',      'url' => '/api/v1/teams/{teamId}/messages/config',        'verb' => 'GET'],
        ['name' => 'team#saveMessagesConfig',     'url' => '/api/v1/teams/{teamId}/messages/config',        'verb' => 'PUT'],
        ['name' => 'message#updateMessage',       'url' => '/api/v1/teams/{teamId}/messages/{messageId}',   'verb' => 'PUT'],
        ['name' => 'message#deleteMessage',       'url' => '/api/v1/teams/{teamId}/messages/{messageId}',   'verb' => 'DELETE'],
        ['name' => 'message#pinMessage',          'url' => '/api/v1/teams/{teamId}/messages/{messageId}/pin',   'verb' => 'POST'],
        ['name' => 'message#unpinMessage',        'url' => '/api/v1/teams/{teamId}/messages/{messageId}/unpin', 'verb' => 'POST'],
        ['name' => 'message#getMessageSettings',  'url' => '/api/v1/teams/{teamId}/messages/settings',      'verb' => 'GET'],
        ['name' => 'message#saveMessageSettings', 'url' => '/api/v1/teams/{teamId}/messages/settings',      'verb' => 'POST'],
        ['name' => 'message#getAggregatedMessages','url' => '/api/v1/messages/aggregated',                   'verb' => 'GET'],
        // v4.2.11 — public feed: is_public messages across every team on
        // this instance. Member-callable (any authenticated user). MUST
        // remain above the `{messageId}` catch-alls below, otherwise the
        // path `public` would be captured by votePoll/getPollResults etc.
        ['name' => 'message#listPublicMessages',  'url' => '/api/v1/messages/public',                        'verb' => 'GET'],
        // v4.2.12 — personal "What's happening" feed: team + public in
        // one paginated call. Same routing rule as `public` above — this
        // fixed segment must stay above the `{messageId}` catchalls.
        ['name' => 'message#getPersonalFeed',     'url' => '/api/v1/messages/feed',                          'verb' => 'GET'],
        // v4.5.26 — the Feed control rail's "Save as default". Personal, no
        // team scope, stored in oc_preferences. Same fixed-segment rule as
        // `feed` and `public` above: it must stay ahead of the {messageId}
        // catch-alls, otherwise `feed` is read as a message id.
        ['name' => 'message#getFeedPreferences',  'url' => '/api/v1/messages/feed/preferences',              'verb' => 'GET'],
        ['name' => 'message#saveFeedPreferences', 'url' => '/api/v1/messages/feed/preferences',              'verb' => 'PUT'],
        ['name' => 'message#votePoll',            'url' => '/api/v1/messages/{messageId}/vote',              'verb' => 'POST'],
        ['name' => 'message#getPollResults',      'url' => '/api/v1/messages/{messageId}/poll-results',      'verb' => 'GET'],
        ['name' => 'message#closePoll',           'url' => '/api/v1/messages/{messageId}/close-poll',        'verb' => 'POST'],
        ['name' => 'message#markQuestionSolved',  'url' => '/api/v1/messages/{messageId}/mark-solved',       'verb' => 'POST'],
        ['name' => 'message#unmarkQuestionSolved','url' => '/api/v1/messages/{messageId}/unmark-solved',     'verb' => 'POST'],
        ['name' => 'message#registerAttachment',  'url' => '/api/v1/messages/{messageId}/attachments',       'verb' => 'POST'],
        ['name' => 'message#cacheImage',          'url' => '/api/v1/teams/{teamId}/messages/cache-image',   'verb' => 'POST'],
        ['name' => 'message#clearImageCache',     'url' => '/api/v1/teams/{teamId}/messages/image-cache',   'verb' => 'DELETE'],

        // ----------------------------------------------------------------
        // My Work (v4.5.21) — personal, cross-team work queue.
        //
        // No route carries a {teamId}: My Work is cross-team by definition and
        // the team boundary is resolved server-side from the session user's own
        // memberships. Actions POST their target in the body rather than the
        // path, because provider item ids are opaque strings and NC's routing
        // strips trailing suffixes from path segments as format hints.
        // ----------------------------------------------------------------
        ['name' => 'myWork#getWork',          'url' => '/api/v1/mywork',             'verb' => 'GET'],
        ['name' => 'myWork#getCounts',        'url' => '/api/v1/mywork/counts',      'verb' => 'GET'],
        ['name' => 'myWork#getProviders',     'url' => '/api/v1/mywork/providers',   'verb' => 'GET'],
        ['name' => 'myWork#getPreferences',   'url' => '/api/v1/mywork/preferences', 'verb' => 'GET'],
        ['name' => 'myWork#savePreferences',  'url' => '/api/v1/mywork/preferences', 'verb' => 'PUT'],
        ['name' => 'myWork#executeAction',    'url' => '/api/v1/mywork/action',      'verb' => 'POST'],

        // My Work administration — instance-admin only, enforced by the
        // absence of #[NoAdminRequired] on every method of the controller.
        ['name' => 'myWorkAdmin#getConfig',    'url' => '/api/v1/admin/mywork/config',                'verb' => 'GET'],
        ['name' => 'myWorkAdmin#saveConfig',   'url' => '/api/v1/admin/mywork/config',                'verb' => 'PUT'],
        ['name' => 'myWorkAdmin#getStatus',    'url' => '/api/v1/admin/mywork/status',                'verb' => 'GET'],
        ['name' => 'myWorkAdmin#saveProvider', 'url' => '/api/v1/admin/mywork/providers/{providerId}', 'verb' => 'PUT'],

        // ----------------------------------------------------------------
        // Team image — upload, remove, serve
        // ----------------------------------------------------------------
        ['name' => 'teamImage#upload', 'url' => '/api/v1/teams/{teamId}/image', 'verb' => 'POST'],
        ['name' => 'teamImage#remove', 'url' => '/api/v1/teams/{teamId}/image', 'verb' => 'DELETE'],
        ['name' => 'teamImage#serve',  'url' => '/api/v1/teams/{teamId}/image', 'verb' => 'GET'],

        // ----------------------------------------------------------------
        // Maintenance & telemetry (NC admin only)
        // ----------------------------------------------------------------
        ['name' => 'maintenance#getAllTeams',        'url' => '/api/v1/admin/maintenance/teams',                                'verb' => 'GET'],
        ['name' => 'maintenance#getOrphanedTeams',  'url' => '/api/v1/admin/maintenance/orphaned-teams',                       'verb' => 'GET'],
        ['name' => 'maintenance#deleteOrphanedTeam','url' => '/api/v1/admin/maintenance/orphaned-teams/{teamId}',              'verb' => 'DELETE'],
        ['name' => 'maintenance#assignOwner',        'url' => '/api/v1/admin/maintenance/orphaned-teams/{teamId}/assign-owner','verb' => 'POST'],
        ['name' => 'maintenance#getTelemetry',       'url' => '/api/v1/admin/telemetry',                                       'verb' => 'GET'],
        ['name' => 'maintenance#saveTelemetry',      'url' => '/api/v1/admin/telemetry',                                       'verb' => 'PUT'],
        ['name' => 'maintenance#searchUsers',        'url' => '/api/v1/admin/users/search',                                    'verb' => 'GET'],
        ['name' => 'maintenance#checkMembershipIntegrity',  'url' => '/api/v1/admin/maintenance/membership-check',                   'verb' => 'GET'],
        ['name' => 'maintenance#repairMembershipCache',     'url' => '/api/v1/admin/maintenance/membership-repair/{teamId}',         'verb' => 'POST'],
        ['name' => 'maintenance#fixDisplayName',             'url' => '/api/v1/admin/maintenance/fix-display-name/{teamId}',        'verb' => 'POST'],
        ['name' => 'maintenance#repairMissingOwner',         'url' => '/api/v1/admin/maintenance/assign-owner/{teamId}',           'verb' => 'POST'],
        ['name' => 'maintenance#repairDuplicateMember',      'url' => '/api/v1/admin/maintenance/repair-duplicate-member/{teamId}', 'verb' => 'POST'],
        ['name' => 'maintenance#clearCfgSingle',            'url' => '/api/v1/admin/maintenance/clear-cfg-single/{teamId}',         'verb' => 'POST'],
        ['name' => 'maintenance#removeNestedTeam',          'url' => '/api/v1/admin/maintenance/nested-team',                       'verb' => 'DELETE'],
        ['name' => 'maintenance#findGhostMembers',          'url' => '/api/v1/admin/maintenance/ghost-members',                     'verb' => 'GET'],
        ['name' => 'maintenance#removeGhostMember',         'url' => '/api/v1/admin/maintenance/ghost-members/{userId}',            'verb' => 'DELETE'],
        ['name' => 'maintenance#resetTeamConfig',           'url' => '/api/v1/admin/maintenance/reset-team-config/{teamId}',        'verb' => 'POST'],
        ['name' => 'maintenance#checkConfigIntegrity',      'url' => '/api/v1/admin/maintenance/config-check',                      'verb' => 'GET'],
        ['name' => 'maintenance#listTeamsForUser',          'url' => '/api/v1/admin/maintenance/users/{userId}/teams',              'verb' => 'GET'],
        ['name' => 'maintenance#removeUserFromTeams',       'url' => '/api/v1/admin/maintenance/users/{userId}/remove-from-teams', 'verb' => 'POST'],

        // ----------------------------------------------------------------
        // Bulk team import (v4.6.6) — NC admin only. Every method in
        // TeamImportController carries #[AuthorizedAdminSetting] and the
        // service re-checks; there is no #[NoAdminRequired] in that file.
        //
        // Order matters: /template and /validate are registered before
        // /{id}, otherwise the literal segments would be captured as an id.
        // Same trap as the messages/config note further down this file.
        // ----------------------------------------------------------------
        ['name' => 'teamImport#template', 'url' => '/api/v1/admin/import/teams/template',      'verb' => 'GET'],
        ['name' => 'teamImport#validate', 'url' => '/api/v1/admin/import/teams/validate',      'verb' => 'POST'],
        ['name' => 'teamImport#start',    'url' => '/api/v1/admin/import/teams/{id}/start',    'verb' => 'POST'],
        ['name' => 'teamImport#process',  'url' => '/api/v1/admin/import/teams/{id}/process',  'verb' => 'POST'],
        ['name' => 'teamImport#show',     'url' => '/api/v1/admin/import/teams/{id}',          'verb' => 'GET'],
        ['name' => 'teamImport#destroy',  'url' => '/api/v1/admin/import/teams/{id}',          'verb' => 'DELETE'],
        ['name' => 'teamImport#index',    'url' => '/api/v1/admin/import/teams',               'verb' => 'GET'],

        // ----------------------------------------------------------------
        // Link preview — server-side Open Graph metadata resolver
        // ----------------------------------------------------------------
        ['name' => 'linkPreview#resolve',    'url' => '/api/v1/preview',       'verb' => 'GET'],
        // Image proxy — serves external OG images through TeamHub to avoid NC CSP violations
        ['name' => 'linkPreview#proxyImage', 'url' => '/api/v1/preview/image', 'verb' => 'GET'],

        // ----------------------------------------------------------------
        // Web links routes
        // ----------------------------------------------------------------
        ['name' => 'webLink#listLinks',           'url' => '/api/v1/teams/{teamId}/links',                   'verb' => 'GET'],
        ['name' => 'webLink#createLink',          'url' => '/api/v1/teams/{teamId}/links',                   'verb' => 'POST'],
        ['name' => 'webLink#updateLink',          'url' => '/api/v1/teams/{teamId}/links/{linkId}',          'verb' => 'PUT'],
        ['name' => 'webLink#deleteLink',          'url' => '/api/v1/teams/{teamId}/links/{linkId}',          'verb' => 'DELETE'],

        // ----------------------------------------------------------------
        // Comment routes
        // ----------------------------------------------------------------
        ['name' => 'comment#listComments',        'url' => '/api/v1/messages/{messageId}/comments',          'verb' => 'GET'],
        ['name' => 'comment#createComment',       'url' => '/api/v1/messages/{messageId}/comments',          'verb' => 'POST'],
        ['name' => 'comment#updateComment',       'url' => '/api/v1/comments/{commentId}',                   'verb' => 'PUT'],
        ['name' => 'comment#deleteComment',       'url' => '/api/v1/comments/{commentId}',                   'verb' => 'DELETE'],

        // ----------------------------------------------------------------
        // v4.5.26 — "What's new" Talk interaction. Reply inside a thread and
        // vote on a poll without leaving the feed.
        //
        // Every one of these resolves {token} back to a team the caller is a
        // member of before doing anything — the same room→team mapping the
        // feed used to decide the row was theirs to see. Talk then applies its
        // own participant and read-only rules on top. Two independent gates:
        // neither one alone is the boundary.
        // ----------------------------------------------------------------
        ['name' => 'feedTalk#listThreadReplies', 'url' => '/api/v1/feed/talk/{token}/threads/{threadId}/replies', 'verb' => 'GET'],
        ['name' => 'feedTalk#replyToThread',     'url' => '/api/v1/feed/talk/{token}/threads/{threadId}/replies', 'verb' => 'POST'],
        ['name' => 'feedTalk#votePoll',          'url' => '/api/v1/feed/talk/{token}/polls/{pollId}/vote',        'verb' => 'POST'],

        // ----------------------------------------------------------------
        // Layout — per-user, per-team Home-view grid + tab order
        // ----------------------------------------------------------------
        ['name' => 'layout#getLayout',         'url' => '/api/v1/teams/{teamId}/layout', 'verb' => 'GET'],
        ['name' => 'layout#saveLayout',        'url' => '/api/v1/teams/{teamId}/layout', 'verb' => 'PUT'],
        // User personal default layout — stored in oc_preferences, no team scope.
        ['name' => 'layout#getDefaultLayout',  'url' => '/api/v1/layout/default',        'verb' => 'GET'],
        ['name' => 'layout#saveDefaultLayout', 'url' => '/api/v1/layout/default',        'verb' => 'PUT'],

        // ----------------------------------------------------------------
        // Team meeting action — notes file + calendar event + Talk room
        // ----------------------------------------------------------------
        ['name' => 'meeting#createTeamMeeting',  'url' => '/api/v1/teams/{teamId}/meetings',          'verb' => 'POST'],
        ['name' => 'meeting#getMeetingSettings', 'url' => '/api/v1/teams/{teamId}/meetings/settings', 'verb' => 'GET'],
        ['name' => 'meeting#saveMeetingSettings','url' => '/api/v1/teams/{teamId}/meetings/settings', 'verb' => 'PUT'],

        // ----------------------------------------------------------------
        // Feedback & feature requests
        // ----------------------------------------------------------------
        ['name' => 'feedback#submit', 'url' => '/api/v1/feedback', 'verb' => 'POST'],

        // ----------------------------------------------------------------
        // Per-user UI preferences (v4.4.12) — oc_preferences, no team scope.
        // ----------------------------------------------------------------
        ['name' => 'preferences#getPreferences',  'url' => '/api/v1/preferences', 'verb' => 'GET'],
        ['name' => 'preferences#savePreferences', 'url' => '/api/v1/preferences', 'verb' => 'PUT'],

        // ----------------------------------------------------------------
        // In-app announcements (v4.4.17) — unlicensed instances only.
        // ----------------------------------------------------------------
        // filename passed as ?filename= / body param — NC's Symfony routing
        // treats a trailing .md as a request format on {filename} URL slots.
        ['name' => 'announcement#list',    'url' => '/api/v1/announcements',         'verb' => 'GET'],
        ['name' => 'announcement#get',     'url' => '/api/v1/announcements/body',    'verb' => 'GET'],
        ['name' => 'announcement#dismiss', 'url' => '/api/v1/announcements/dismiss', 'verb' => 'POST'],

        // ----------------------------------------------------------------
        // Integration API — external-app registration (NC admin required)
        // ----------------------------------------------------------------
        ['name' => 'integration#listRegisteredIntegrations', 'url' => '/api/v1/ext/integrations',          'verb' => 'GET'],
        ['name' => 'integration#registerIntegration',        'url' => '/api/v1/ext/integrations/register', 'verb' => 'POST'],
        ['name' => 'integration#deregisterIntegration',      'url' => '/api/v1/ext/integrations/{appId}',  'verb' => 'DELETE'],

        // Integration — team render endpoints (called on team select)
        ['name' => 'integration#getEnabledIntegrations', 'url' => '/api/v1/teams/{teamId}/integrations',                              'verb' => 'GET'],
        ['name' => 'integration#getWidgetData',          'url' => '/api/v1/teams/{teamId}/integrations/widget-data/{registryId}',     'verb' => 'GET'],
        ['name' => 'integration#getActionForm',          'url' => '/api/v1/teams/{teamId}/integrations/action-form/{registryId}',     'verb' => 'GET'],
        ['name' => 'integration#submitAction',           'url' => '/api/v1/teams/{teamId}/integrations/action-submit/{registryId}',   'verb' => 'POST'],

        // Integration — Manage Team → Integrations tab
        ['name' => 'integration#getIntegrationRegistry', 'url' => '/api/v1/teams/{teamId}/integrations/registry',                    'verb' => 'GET'],
        ['name' => 'integration#toggleIntegration',      'url' => '/api/v1/teams/{teamId}/integrations/{registryId}/toggle',         'verb' => 'POST'],
        ['name' => 'integration#reorderIntegrations',    'url' => '/api/v1/teams/{teamId}/integrations/reorder',                     'verb' => 'PUT'],

        // ----------------------------------------------------------------
        // Audit log — admin governance (NC admin required)
        // ----------------------------------------------------------------
        ['name' => 'audit#listTeams',     'url' => '/api/v1/admin/audit/teams',                          'verb' => 'GET'],
        ['name' => 'audit#listEvents',    'url' => '/api/v1/admin/audit/teams/{teamId}/events',         'verb' => 'GET'],
        ['name' => 'audit#exportTeam',    'url' => '/api/v1/admin/audit/teams/{teamId}/export',         'verb' => 'GET'],
        ['name' => 'audit#getRetention',  'url' => '/api/v1/admin/audit/retention',                      'verb' => 'GET'],
        ['name' => 'audit#saveRetention', 'url' => '/api/v1/admin/audit/retention',                      'verb' => 'PUT'],

        // ----------------------------------------------------------------
        // Compliance — code-integrity check (v4.2.0)
        // Admin-only. Verifies shipped files against appinfo/integrity.json.
        // ----------------------------------------------------------------
        ['name' => 'integrity#check',     'url' => '/api/v1/admin/integrity',                            'verb' => 'GET'],

        // v4.2.10 — Aggregated governance-risk counts (ghost memberships
        // + orphan teams) for the Compliance-tab summary pills.
        ['name' => 'maintenance#complianceSummary', 'url' => '/api/v1/admin/compliance/summary',         'verb' => 'GET'],

        // ----------------------------------------------------------------
        // Archive — owner initiation + admin governance
        // ----------------------------------------------------------------
        // Owner: initiate archive-and-delete for the team.
        ['name' => 'archive#archiveTeam',             'url' => '/api/v1/teams/{teamId}/archive',                          'verb' => 'POST'],
        ['name' => 'archive#softDeleteTeam',          'url' => '/api/v1/teams/{teamId}/soft-delete',                      'verb' => 'POST'],
        // Owner or admin: poll the pending-deletion status of a team.
        ['name' => 'archive#getArchiveStatus',        'url' => '/api/v1/teams/{teamId}/archive/status',                   'verb' => 'GET'],
        // Admin: list all pending-deletion rows (paginated).
        ['name' => 'archive#listPendingDeletions',    'url' => '/api/v1/admin/archive/pending',                           'verb' => 'GET'],
        // Admin: restore a team within its grace period.
        ['name' => 'archive#restorePendingDeletion',  'url' => '/api/v1/admin/archive/pending/{id}/restore',              'verb' => 'POST'],
        // Admin: force immediate hard-delete regardless of remaining grace period.
        ['name' => 'archive#purgePendingDeletion',    'url' => '/api/v1/admin/archive/pending/{id}/purge',                'verb' => 'POST'],
        // Admin: discard a failed archive row without deleting the team.
        ['name' => 'archive#discardFailedArchive',    'url' => '/api/v1/admin/archive/pending/{id}',                     'verb' => 'DELETE'],
        // Admin: retry a failed archive (admin-level, bypasses owner check).
        ['name' => 'archive#retryArchive',            'url' => '/api/v1/admin/archive/pending/{id}/retry',               'verb' => 'POST'],
        // Admin: read archive settings.
        ['name' => 'archive#getAdminArchiveSettings', 'url' => '/api/v1/admin/archive/settings',                          'verb' => 'GET'],
        // Admin: save archive settings.
        ['name' => 'archive#saveAdminArchiveSettings','url' => '/api/v1/admin/archive/settings',                          'verb' => 'PUT'],

        // ----------------------------------------------------------------
        // Presence module — admin (v3.42.0, Session B1)
        // All routes are #[AuthorizedAdminSetting] inside the controller;
        // path-level admin scoping is encoded in the URL prefix.
        // ----------------------------------------------------------------
        // Status types
        ['name' => 'presenceAdmin#listTypes',        'url' => '/api/v1/admin/presence/types',                            'verb' => 'GET'],
        ['name' => 'presenceAdmin#createType',       'url' => '/api/v1/admin/presence/types',                            'verb' => 'POST'],
        ['name' => 'presenceAdmin#updateType',       'url' => '/api/v1/admin/presence/types/{id}',                       'verb' => 'PUT'],
        ['name' => 'presenceAdmin#deleteType',       'url' => '/api/v1/admin/presence/types/{id}',                       'verb' => 'DELETE'],
        // Locations — tree read + per-level CRUD
        ['name' => 'presenceAdmin#getLocationTree',  'url' => '/api/v1/admin/presence/locations',                        'verb' => 'GET'],
        ['name' => 'presenceAdmin#createBuilding',   'url' => '/api/v1/admin/presence/buildings',                        'verb' => 'POST'],
        ['name' => 'presenceAdmin#updateBuilding',   'url' => '/api/v1/admin/presence/buildings/{id}',                   'verb' => 'PUT'],
        ['name' => 'presenceAdmin#deleteBuilding',   'url' => '/api/v1/admin/presence/buildings/{id}',                   'verb' => 'DELETE'],
        ['name' => 'presenceAdmin#createFloor',      'url' => '/api/v1/admin/presence/floors',                           'verb' => 'POST'],
        ['name' => 'presenceAdmin#updateFloor',      'url' => '/api/v1/admin/presence/floors/{id}',                      'verb' => 'PUT'],
        ['name' => 'presenceAdmin#deleteFloor',      'url' => '/api/v1/admin/presence/floors/{id}',                      'verb' => 'DELETE'],
        ['name' => 'presenceAdmin#createRoom',       'url' => '/api/v1/admin/presence/rooms',                            'verb' => 'POST'],
        ['name' => 'presenceAdmin#updateRoom',       'url' => '/api/v1/admin/presence/rooms/{id}',                       'verb' => 'PUT'],
        ['name' => 'presenceAdmin#deleteRoom',       'url' => '/api/v1/admin/presence/rooms/{id}',                       'verb' => 'DELETE'],
        // Holidays
        ['name' => 'presenceAdmin#listHolidays',     'url' => '/api/v1/admin/presence/holidays',                         'verb' => 'GET'],
        ['name' => 'presenceAdmin#previewHoliday',   'url' => '/api/v1/admin/presence/holidays/preview',                 'verb' => 'POST'],
        ['name' => 'presenceAdmin#addHoliday',       'url' => '/api/v1/admin/presence/holidays',                         'verb' => 'POST'],
        ['name' => 'presenceAdmin#deleteHoliday',    'url' => '/api/v1/admin/presence/holidays/{id}',                    'verb' => 'DELETE'],
        // Presence module — user (v3.43.0, Session B2)
        // Authenticated users only; no admin required; data scoped to current user.
        ['name' => 'presenceUser#getTemplate',       'url' => '/api/v1/presence/template',          'verb' => 'GET'],
        ['name' => 'presenceUser#getTypes',          'url' => '/api/v1/presence/types',             'verb' => 'GET'],
        ['name' => 'presenceUser#getLocations',      'url' => '/api/v1/presence/locations',         'verb' => 'GET'],
        ['name' => 'presenceUser#setTemplateCell',   'url' => '/api/v1/presence/template/cell',    'verb' => 'PUT'],
        ['name' => 'presenceUser#saveTemplateBulk',  'url' => '/api/v1/presence/template/bulk',    'verb' => 'PUT'],
        ['name' => 'presenceUser#materialiseNow',    'url' => '/api/v1/presence/slots/materialise','verb' => 'POST'],
        ['name' => 'presenceUser#getSlots',          'url' => '/api/v1/presence/slots',             'verb' => 'GET'],
        ['name' => 'presenceUser#overrideSlot',      'url' => '/api/v1/presence/slots/override',   'verb' => 'PUT'],

        // Presence module — team (v3.44.0, Session B3)
        // Team-member-gated; config writes require team admin.
        ['name' => 'presenceTeam#getTeamGrid', 'url' => '/api/v1/teams/{teamId}/presence',        'verb' => 'GET'],
        ['name' => 'presenceTeam#getConfig',   'url' => '/api/v1/teams/{teamId}/presence/config', 'verb' => 'GET'],
        ['name' => 'presenceTeam#suggestTimes', 'url' => '/api/v1/teams/{teamId}/presence/suggest-times', 'verb' => 'GET'],
        ['name' => 'presenceTeam#suggestTimeslots', 'url' => '/api/v1/teams/{teamId}/presence/suggest-timeslots', 'verb' => 'GET'],
        ['name' => 'presenceTeam#saveConfig',  'url' => '/api/v1/teams/{teamId}/presence/config', 'verb' => 'PUT'],

        // Decisions module — team config (v3.64.0, Session A)
        // Both endpoints require the global module flag to be on AND team membership.
        // saveConfig additionally requires team admin.
        ['name' => 'decision#getConfig',  'url' => '/api/v1/teams/{teamId}/decisions/config', 'verb' => 'GET'],
        ['name' => 'decision#saveConfig', 'url' => '/api/v1/teams/{teamId}/decisions/config', 'verb' => 'PUT'],

        // Timeline module — per-team enable flag (v3.77.20)
        // Stored as app-config: timeline_enabled_<teamId> = "1" or "0".
        // Default is enabled. Disabling hides the Timeline tab for that team.
        ['name' => 'team#getTimelineConfig',  'url' => '/api/v1/teams/{teamId}/timeline/config', 'verb' => 'GET'],
        ['name' => 'team#saveTimelineConfig', 'url' => '/api/v1/teams/{teamId}/timeline/config', 'verb' => 'PUT'],

        // Team template label (v4.0.2) — collaboration | project | department.
        // Written once by the create wizard; read by the Team info widget and
        // Browse Teams to render a template badge.
        ['name' => 'team#getTeamType',  'url' => '/api/v1/teams/{teamId}/type', 'verb' => 'GET'],
        ['name' => 'team#saveTeamType', 'url' => '/api/v1/teams/{teamId}/type', 'verb' => 'PUT'],

        // Team-wide dashboard customization — hidden widgets + default tab.
        // Stored as app-config: dashboard_hidden_<teamId> (JSON array) and
        // dashboard_tab_<teamId> (string). Read by members, written by admins.
        ['name' => 'team#getDashboardConfig',  'url' => '/api/v1/teams/{teamId}/dashboard/config', 'verb' => 'GET'],
        ['name' => 'team#saveDashboardConfig', 'url' => '/api/v1/teams/{teamId}/dashboard/config', 'verb' => 'PUT'],

        // Project Teams (v3.88.0) — persisted project-ness + Basic/Advanced mode
        // + PMC phase. get is team-member-gated; save + setPhase require team admin.
        // setPhase is valid only on advanced projects.
        ['name' => 'project#get',       'url' => '/api/v1/teams/{teamId}/project',        'verb' => 'GET'],
        ['name' => 'project#save',      'url' => '/api/v1/teams/{teamId}/project',        'verb' => 'PUT'],
        ['name' => 'project#setPhase',  'url' => '/api/v1/teams/{teamId}/project/phase',  'verb' => 'PUT'],
        ['name' => 'project#getHealth',    'url' => '/api/v1/teams/{teamId}/project/health',    'verb' => 'GET'],
        ['name' => 'project#getReadiness',             'url' => '/api/v1/teams/{teamId}/project/readiness',              'verb' => 'GET'],
        ['name' => 'project#setMark',                  'url' => '/api/v1/teams/{teamId}/project/marks/{markType}',       'verb' => 'PUT'],
        ['name' => 'project#generateClosingArtifact',  'url' => '/api/v1/teams/{teamId}/project/closing/generate',       'verb' => 'POST'],
        ['name' => 'project#getClosingStatus',         'url' => '/api/v1/teams/{teamId}/project/closing/status',         'verb' => 'GET'],
        ['name' => 'project#getArchivePolicy',         'url' => '/api/v1/teams/{teamId}/project/closing/archive-policy', 'verb' => 'GET'],

        // Project Budget (v3.92.0, Track E Session 4) — Execution-phase budget
        // page. get is member-gated (per-lane view_min_level filters the
        // response inside the service). setTotal + updateLane require team
        // admin. Expense CRUD is per-lane edit_min_level-gated.
        ['name' => 'team#getBudgetConfig',  'url' => '/api/v1/teams/{teamId}/budget/config',                                  'verb' => 'GET'],
        ['name' => 'team#saveBudgetConfig', 'url' => '/api/v1/teams/{teamId}/budget/config',                                  'verb' => 'PUT'],
        ['name' => 'budget#get',           'url' => '/api/v1/teams/{teamId}/budget',                                          'verb' => 'GET'],
        ['name' => 'budget#setTotal',      'url' => '/api/v1/teams/{teamId}/budget',                                          'verb' => 'PUT'],
        ['name' => 'budget#updateLane',    'url' => '/api/v1/teams/{teamId}/budget/lanes/{laneId}',                            'verb' => 'PUT'],
        ['name' => 'budget#addExpense',    'url' => '/api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses',                   'verb' => 'POST'],
        ['name' => 'budget#updateExpense', 'url' => '/api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses/{expenseId}',       'verb' => 'PUT'],
        ['name' => 'budget#deleteExpense', 'url' => '/api/v1/teams/{teamId}/budget/lanes/{laneId}/expenses/{expenseId}',       'verb' => 'DELETE'],

        // Project Time investment (v3.96.0, Track E Session 5) — Execution-phase
        // per-member available hours + per-Deck-card time logs. get is member +
        // tab-visibility gated (view-floor OR project-member row). Config
        // writes require team admin. Log writes require the caller to be a
        // live Deck-card assignee (admins can log on behalf).
        ['name' => 'team#getTimeConfig',   'url' => '/api/v1/teams/{teamId}/time/config',                    'verb' => 'GET'],
        ['name' => 'team#saveTimeConfig',  'url' => '/api/v1/teams/{teamId}/time/config',                    'verb' => 'PUT'],
        ['name' => 'time#get',             'url' => '/api/v1/teams/{teamId}/time',                           'verb' => 'GET'],
        ['name' => 'time#setConfig',       'url' => '/api/v1/teams/{teamId}/time',                           'verb' => 'PUT'],
        ['name' => 'time#loggableCards',   'url' => '/api/v1/teams/{teamId}/time/loggable-cards',            'verb' => 'GET'],
        ['name' => 'time#upsertMember',    'url' => '/api/v1/teams/{teamId}/time/members/{userId}',          'verb' => 'PUT'],
        ['name' => 'time#removeMember',    'url' => '/api/v1/teams/{teamId}/time/members/{userId}',          'verb' => 'DELETE'],
        ['name' => 'time#getMemberLogs',   'url' => '/api/v1/teams/{teamId}/time/members/{userId}/logs',     'verb' => 'GET'],
        ['name' => 'time#addLog',          'url' => '/api/v1/teams/{teamId}/time/logs',                      'verb' => 'POST'],
        ['name' => 'time#updateLog',       'url' => '/api/v1/teams/{teamId}/time/logs/{logId}',              'verb' => 'PUT'],
        ['name' => 'time#deleteLog',       'url' => '/api/v1/teams/{teamId}/time/logs/{logId}',              'verb' => 'DELETE'],

        // Timeline Milestones (v3.78.2) — admin-managed marker lines on the
        // Timeline tab. Managed from Manage Team → Integration settings.
        ['name' => 'team#getMilestones',    'url' => '/api/v1/teams/{teamId}/milestones',                'verb' => 'GET'],
        ['name' => 'team#pickMilestones',   'url' => '/api/v1/teams/{teamId}/milestones/pick',           'verb' => 'GET'],
        ['name' => 'team#createDeckStack',  'url' => '/api/v1/teams/{teamId}/deck/stacks',               'verb' => 'POST'],
        ['name' => 'team#createMilestone',  'url' => '/api/v1/teams/{teamId}/milestones',                'verb' => 'POST'],
        ['name' => 'team#updateMilestone',  'url' => '/api/v1/teams/{teamId}/milestones/{milestoneId}', 'verb' => 'PUT'],
        ['name' => 'team#deleteMilestone',  'url' => '/api/v1/teams/{teamId}/milestones/{milestoneId}', 'verb' => 'DELETE'],

        // Decisions module — feature endpoints (v3.65.0, Session B)
        // All require global flag on, per-team flag on, and team membership.
        // Mark/withdraw additionally require proposer OR admin level.
        ['name' => 'decision#index',      'url' => '/api/v1/teams/{teamId}/decisions',                          'verb' => 'GET'],
        ['name' => 'decision#propose',    'url' => '/api/v1/teams/{teamId}/decisions',                          'verb' => 'POST'],
        ['name' => 'decision#categories', 'url' => '/api/v1/teams/{teamId}/decisions/categories',               'verb' => 'GET'],
        ['name' => 'decision#sources',    'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/sources',    'verb' => 'GET'],

        // v3.71.10 — proposal source file content (read-only viewer)
        ['name' => 'decision#fileContent', 'url' => '/api/v1/files/{fileId}/content',                          'verb' => 'GET'],

        // Session G — predefined-category management (admin-only writes).
        ['name' => 'decision#listCategories',   'url' => '/api/v1/teams/{teamId}/decisions/manage/categories',                  'verb' => 'GET'],
        ['name' => 'decision#createCategory',   'url' => '/api/v1/teams/{teamId}/decisions/manage/categories',                  'verb' => 'POST'],
        ['name' => 'decision#updateCategory',   'url' => '/api/v1/teams/{teamId}/decisions/manage/categories/{categoryId}',     'verb' => 'PUT'],
        ['name' => 'decision#deleteCategory',   'url' => '/api/v1/teams/{teamId}/decisions/manage/categories/{categoryId}',     'verb' => 'DELETE'],
        ['name' => 'decision#show',       'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}',             'verb' => 'GET'],
        // Session H — lifecycle endpoints.
        // 'finalize' replaces the legacy 'mark' route. Old clients in flight
        // before upgrade get a 404; intended — we wipe test data per Session G plan.
        ['name' => 'decision#finalize',   'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/finalize',    'verb' => 'POST'],
        ['name' => 'decision#refreshProposal', 'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/refresh-proposal', 'verb' => 'POST'],
        // v4.5.42 — the discussion phase: edit an open proposal, finalize it
        // from its own body, and record where it is being discussed.
        ['name' => 'decision#updateProposal',   'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/proposal',          'verb' => 'PUT'],
        ['name' => 'decision#finalizeProposal', 'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/finalize-proposal', 'verb' => 'POST'],
        ['name' => 'decision#share',            'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/share',             'verb' => 'POST'],
        ['name' => 'decision#withdraw',   'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/withdraw',    'verb' => 'POST'],
        ['name' => 'decision#approve',    'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/approve',     'verb' => 'POST'],
        ['name' => 'decision#deny',       'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/deny',        'verb' => 'POST'],
        // Session J — audit timeline (read-only).
        ['name' => 'decision#audit',      'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/audit',       'verb' => 'GET'],

        // Session B — decision ↔ task links
        ['name' => 'decision#listTasks',   'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/tasks',            'verb' => 'GET'],
        ['name' => 'decision#createTask',  'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/tasks',            'verb' => 'POST'],
        ['name' => 'decision#deleteTask',  'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/tasks/{taskId}',   'verb' => 'DELETE'],

        // Session C — decision ↔ decision links
        ['name' => 'decision#listDecisionLinks',   'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/links',           'verb' => 'GET'],
        ['name' => 'decision#createDecisionLink',  'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/links',           'verb' => 'POST'],
        ['name' => 'decision#deleteDecisionLink',  'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/links/{linkId}',  'verb' => 'DELETE'],

        // Session — approver meetings on a proposal (v3.74.10)
        ['name' => 'decision#listApprovers',      'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/approvers', 'verb' => 'GET'],
        ['name' => 'decision#listMeetings',       'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/meetings',  'verb' => 'GET'],
        ['name' => 'decision#createMeeting',      'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/meetings',  'verb' => 'POST'],

        // External decision links (v3.75.2)
        ['name' => 'decision#listExternalLinks',   'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/external-links',          'verb' => 'GET'],
        ['name' => 'decision#createExternalLink',  'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/external-links',          'verb' => 'POST'],
        ['name' => 'decision#deleteExternalLink',  'url' => '/api/v1/teams/{teamId}/decisions/{decisionId}/external-links/{linkId}', 'verb' => 'DELETE'],

        // ----------------------------------------------------------------
        // Licensing (v3.100.0, Track F). Admin endpoints are admin-gated
        // in the controller; entitlements is member-callable so the
        // CreateTeamView wizard can grey out Advanced upfront.
        // ----------------------------------------------------------------
        ['name' => 'license#getStatus',    'url' => '/api/v1/admin/license',         'verb' => 'GET'],
        ['name' => 'license#saveKey',      'url' => '/api/v1/admin/license',         'verb' => 'PUT'],
        ['name' => 'license#entitlements', 'url' => '/api/v1/license/entitlements',  'verb' => 'GET'],
    ],
];
// Note: just checking structure
