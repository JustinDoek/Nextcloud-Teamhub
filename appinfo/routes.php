<?php
declare(strict_types=1);

return [
    'routes' => [
        // Main page route
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

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
        ['name' => 'team#getTeamActivity',        'url' => '/api/v1/teams/{teamId}/activity',                'verb' => 'GET'],
        ['name' => 'team#getTeamCalendarEvents',  'url' => '/api/v1/teams/{teamId}/calendar/events',         'verb' => 'GET'],
        ['name' => 'team#createCalendarEvent',    'url' => '/api/v1/teams/{teamId}/calendar/events',         'verb' => 'POST'],

        // Files widgets — favourite files and recently modified files
        ['name' => 'team#getTeamFavoriteFiles',   'url' => '/api/v1/teams/{teamId}/files/favorites',         'verb' => 'GET'],
        ['name' => 'team#getTeamRecentFiles',      'url' => '/api/v1/teams/{teamId}/files/recent',            'verb' => 'GET'],
        // Shared files widget — files/folders shared directly with the team circle
        ['name' => 'team#getTeamSharedFiles',      'url' => '/api/v1/teams/{teamId}/files/shared',            'verb' => 'GET'],

        // Team apps (enable/disable per team)
        ['name' => 'team#getTeamApps',            'url' => '/api/v1/teams/{teamId}/apps',                    'verb' => 'GET'],
        ['name' => 'team#updateTeamApps',         'url' => '/api/v1/teams/{teamId}/apps',                    'verb' => 'PUT'],
        ['name' => 'team#deleteTeamResource',     'url' => '/api/v1/teams/{teamId}/resources/{app}',         'verb' => 'DELETE'],
        ['name' => 'team#createIntravoxPage',     'url' => '/api/v1/teams/{teamId}/intravox/page',           'verb' => 'POST'],
        ['name' => 'team#deleteIntravoxPage',     'url' => '/api/v1/teams/{teamId}/intravox/page',           'verb' => 'DELETE'],
        ['name' => 'team#getIntravoxSubPages',    'url' => '/api/v1/teams/{teamId}/intravox/subpages',       'verb' => 'GET'],
        ['name' => 'team#invalidateIntravoxCache', 'url' => '/api/v1/teams/{teamId}/intravox/subpages/cache', 'verb' => 'DELETE'],

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
        ['name' => 'message#updateMessage',       'url' => '/api/v1/teams/{teamId}/messages/{messageId}',   'verb' => 'PUT'],
        ['name' => 'message#deleteMessage',       'url' => '/api/v1/teams/{teamId}/messages/{messageId}',   'verb' => 'DELETE'],
        ['name' => 'message#pinMessage',          'url' => '/api/v1/teams/{teamId}/messages/{messageId}/pin',   'verb' => 'POST'],
        ['name' => 'message#unpinMessage',        'url' => '/api/v1/teams/{teamId}/messages/{messageId}/unpin', 'verb' => 'POST'],
        ['name' => 'message#getAggregatedMessages','url' => '/api/v1/messages/aggregated',                   'verb' => 'GET'],
        ['name' => 'message#votePoll',            'url' => '/api/v1/messages/{messageId}/vote',              'verb' => 'POST'],
        ['name' => 'message#getPollResults',      'url' => '/api/v1/messages/{messageId}/poll-results',      'verb' => 'GET'],
        ['name' => 'message#closePoll',           'url' => '/api/v1/messages/{messageId}/close-poll',        'verb' => 'POST'],
        ['name' => 'message#markQuestionSolved',  'url' => '/api/v1/messages/{messageId}/mark-solved',       'verb' => 'POST'],
        ['name' => 'message#unmarkQuestionSolved','url' => '/api/v1/messages/{messageId}/unmark-solved',     'verb' => 'POST'],

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
        ['name' => 'maintenance#checkMembershipIntegrity', 'url' => '/api/v1/admin/maintenance/membership-check',              'verb' => 'GET'],
        ['name' => 'maintenance#repairMembershipCache',    'url' => '/api/v1/admin/maintenance/membership-repair/{teamId}',    'verb' => 'POST'],

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
        // Archive — owner initiation + admin governance
        // ----------------------------------------------------------------
        // Owner: initiate archive-and-delete for the team.
        ['name' => 'archive#archiveTeam',             'url' => '/api/v1/teams/{teamId}/archive',                          'verb' => 'POST'],
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
    ],
];
