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

        // Admin settings
        ['name' => 'team#getAdminSettings',       'url' => '/api/v1/admin/settings',                         'verb' => 'GET'],
        ['name' => 'team#saveAdminSettings',      'url' => '/api/v1/admin/settings',                         'verb' => 'POST'],
        ['name' => 'team#searchAdminGroups',      'url' => '/api/v1/admin/groups/search',                    'verb' => 'GET'],
        ['name' => 'team#getAllowedInviteTypes',  'url' => '/api/v1/invite-types',                            'verb' => 'GET'],

        // Team members
        ['name' => 'team#getTeamMembers',         'url' => '/api/v1/teams/{teamId}/members',                 'verb' => 'GET'],
        ['name' => 'team#removeMember',           'url' => '/api/v1/teams/{teamId}/members/{userId}',        'verb' => 'DELETE'],
        ['name' => 'team#updateMemberLevel',      'url' => '/api/v1/teams/{teamId}/members/{userId}/level',  'verb' => 'PUT'],
        ['name' => 'team#getPendingRequests',     'url' => '/api/v1/teams/{teamId}/pending-requests',        'verb' => 'GET'],
        ['name' => 'team#approveRequest',         'url' => '/api/v1/teams/{teamId}/approve/{userId}',        'verb' => 'POST'],
        ['name' => 'team#rejectRequest',          'url' => '/api/v1/teams/{teamId}/reject/{userId}',         'verb' => 'POST'],

        // Team resources & activity
        ['name' => 'team#getTeamResources',       'url' => '/api/v1/teams/{teamId}/resources',               'verb' => 'GET'],
        ['name' => 'team#getTeamActivity',        'url' => '/api/v1/teams/{teamId}/activity',                'verb' => 'GET'],
        ['name' => 'team#getTeamCalendarEvents',  'url' => '/api/v1/teams/{teamId}/calendar/events',         'verb' => 'GET'],
        ['name' => 'team#createCalendarEvent',    'url' => '/api/v1/teams/{teamId}/calendar/events',         'verb' => 'POST'],

        // Team apps (enable/disable per team)
        ['name' => 'team#getTeamApps',            'url' => '/api/v1/teams/{teamId}/apps',                    'verb' => 'GET'],
        ['name' => 'team#updateTeamApps',         'url' => '/api/v1/teams/{teamId}/apps',                    'verb' => 'PUT'],
        ['name' => 'team#deleteTeamResource',     'url' => '/api/v1/teams/{teamId}/resources/{app}',         'verb' => 'DELETE'],

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

        // ----------------------------------------------------------------
        // Integration API — external-app registration
        // ----------------------------------------------------------------
        ['name' => 'integration#registerIntegration',   'url' => '/api/v1/ext/integrations/register',       'verb' => 'POST'],
        ['name' => 'integration#deregisterIntegration', 'url' => '/api/v1/ext/integrations/{appId}',         'verb' => 'DELETE'],

        // Integration — team render endpoints (called on team select)
        ['name' => 'integration#getEnabledIntegrations', 'url' => '/api/v1/teams/{teamId}/integrations',                              'verb' => 'GET'],
        ['name' => 'integration#getWidgetData',          'url' => '/api/v1/teams/{teamId}/integrations/widget-data/{registryId}',     'verb' => 'GET'],
        ['name' => 'integration#getWidgetAction',        'url' => '/api/v1/teams/{teamId}/integrations/action/{registryId}',          'verb' => 'GET'],

        // Integration — Manage Team → Integrations tab
        ['name' => 'integration#getIntegrationRegistry', 'url' => '/api/v1/teams/{teamId}/integrations/registry',                    'verb' => 'GET'],
        ['name' => 'integration#toggleIntegration',      'url' => '/api/v1/teams/{teamId}/integrations/{registryId}/toggle',         'verb' => 'POST'],
        ['name' => 'integration#reorderIntegrations',    'url' => '/api/v1/teams/{teamId}/integrations/reorder',                     'verb' => 'PUT'],
    ],
];
