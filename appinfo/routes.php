<?php
declare(strict_types=1);

return [
    'routes' => [
        // Main page route
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        
        // Team routes
        ['name' => 'team#listTeams', 'url' => '/api/v1/teams', 'verb' => 'GET'],
        ['name' => 'team#browseAllTeams', 'url' => '/api/v1/teams/browse', 'verb' => 'GET'],
        ['name' => 'team#getTeam', 'url' => '/api/v1/teams/{teamId}', 'verb' => 'GET'],
        ['name' => 'team#createTeam', 'url' => '/api/v1/teams', 'verb' => 'POST'],
        ['name' => 'team#updateTeam', 'url' => '/api/v1/teams/{teamId}', 'verb' => 'PUT'],
        ['name' => 'team#deleteTeam', 'url' => '/api/v1/teams/{teamId}', 'verb' => 'DELETE'],
        // Admin settings
        ['name' => 'team#getAdminSettings',     'url' => '/api/v1/admin/settings',          'verb' => 'GET'],
        ['name' => 'team#saveAdminSettings',     'url' => '/api/v1/admin/settings',          'verb' => 'POST'],
        ['name' => 'team#getAllowedInviteTypes', 'url' => '/api/v1/invite-types',            'verb' => 'GET'],
        ['name' => 'team#getTeamApps', 'url' => '/api/v1/teams/{teamId}/apps', 'verb' => 'GET'],
        ['name' => 'team#updateTeamApps', 'url' => '/api/v1/teams/{teamId}/apps', 'verb' => 'PUT'],
        ['name' => 'team#getTeamMembers', 'url' => '/api/v1/teams/{teamId}/members', 'verb' => 'GET'],
        ['name' => 'team#getTeamResources', 'url' => '/api/v1/teams/{teamId}/resources', 'verb' => 'GET'],
        ['name' => 'team#getTeamActivity',  'url' => '/api/v1/teams/{teamId}/activity',  'verb' => 'GET'],
        ['name' => 'team#getTeamCalendarEvents', 'url' => '/api/v1/teams/{teamId}/calendar/events', 'verb' => 'GET'],
        ['name' => 'team#createCalendarEvent',   'url' => '/api/v1/teams/{teamId}/calendar/events', 'verb' => 'POST'],
        ['name' => 'team#requestJoinTeam', 'url' => '/api/v1/teams/{teamId}/join', 'verb' => 'POST'],
        ['name' => 'team#leaveTeam', 'url' => '/api/v1/teams/{teamId}/leave', 'verb' => 'POST'],
        ['name' => 'team#markTeamSeen', 'url' => '/api/v1/teams/{teamId}/seen', 'verb' => 'POST'],

        // User search (for member picker)
        ['name' => 'team#searchUsers', 'url' => '/api/v1/users/search', 'verb' => 'GET'],
        ['name' => 'team#inviteMembers', 'url' => '/api/v1/teams/{teamId}/invite-members', 'verb' => 'POST'],
        ['name' => 'team#checkApps', 'url' => '/api/v1/apps/check', 'verb' => 'GET'],
        ['name' => 'team#createTeamResources', 'url' => '/api/v1/teams/{teamId}/create-resources', 'verb' => 'POST'],
        ['name' => 'team#getTeamConfig', 'url' => '/api/v1/teams/{teamId}/config', 'verb' => 'GET'],
        ['name' => 'team#updateTeamConfig', 'url' => '/api/v1/teams/{teamId}/config', 'verb' => 'PUT'],

        // Manage Team routes (admin/owner only)
        ['name' => 'team#updateTeamDescription', 'url' => '/api/v1/teams/{teamId}/description', 'verb' => 'PUT'],
        ['name' => 'team#removeMember', 'url' => '/api/v1/teams/{teamId}/members/{userId}', 'verb' => 'DELETE'],
        ['name' => 'team#updateMemberLevel', 'url' => '/api/v1/teams/{teamId}/members/{userId}/level', 'verb' => 'PUT'],
        ['name' => 'team#getPendingRequests', 'url' => '/api/v1/teams/{teamId}/pending-requests', 'verb' => 'GET'],
        ['name' => 'team#approveRequest', 'url' => '/api/v1/teams/{teamId}/approve/{userId}', 'verb' => 'POST'],
        ['name' => 'team#rejectRequest', 'url' => '/api/v1/teams/{teamId}/reject/{userId}', 'verb' => 'POST'],
        ['name' => 'team#canCreateTeam', 'url' => '/api/v1/user/can-create-team', 'verb' => 'GET'],
        
        // Message stream routes
        ['name' => 'message#listMessages', 'url' => '/api/v1/teams/{teamId}/messages', 'verb' => 'GET'],
        ['name' => 'message#createMessage', 'url' => '/api/v1/teams/{teamId}/messages', 'verb' => 'POST'],
        ['name' => 'message#updateMessage', 'url' => '/api/v1/teams/{teamId}/messages/{messageId}', 'verb' => 'PUT'],
        ['name' => 'message#deleteMessage', 'url' => '/api/v1/teams/{teamId}/messages/{messageId}', 'verb' => 'DELETE'],
        ['name' => 'message#pinMessage', 'url' => '/api/v1/teams/{teamId}/messages/{messageId}/pin', 'verb' => 'POST'],
        ['name' => 'message#unpinMessage', 'url' => '/api/v1/teams/{teamId}/messages/{messageId}/unpin', 'verb' => 'POST'],
        ['name' => 'message#getAggregatedMessages', 'url' => '/api/v1/messages/aggregated', 'verb' => 'GET'],
        ['name' => 'message#votePoll', 'url' => '/api/v1/messages/{messageId}/vote', 'verb' => 'POST'],
        ['name' => 'message#getPollResults', 'url' => '/api/v1/messages/{messageId}/poll-results', 'verb' => 'GET'],
        ['name' => 'message#closePoll', 'url' => '/api/v1/messages/{messageId}/close-poll', 'verb' => 'POST'],
        ['name' => 'message#markQuestionSolved', 'url' => '/api/v1/messages/{messageId}/mark-solved', 'verb' => 'POST'],
        ['name' => 'message#unmarkQuestionSolved', 'url' => '/api/v1/messages/{messageId}/unmark-solved', 'verb' => 'POST'],
        
        // Web links routes
        ['name' => 'webLink#listLinks', 'url' => '/api/v1/teams/{teamId}/links', 'verb' => 'GET'],
        ['name' => 'webLink#createLink', 'url' => '/api/v1/teams/{teamId}/links', 'verb' => 'POST'],
        ['name' => 'webLink#updateLink', 'url' => '/api/v1/teams/{teamId}/links/{linkId}', 'verb' => 'PUT'],
        ['name' => 'webLink#deleteLink', 'url' => '/api/v1/teams/{teamId}/links/{linkId}', 'verb' => 'DELETE'],
        
        // Comment routes
        ['name' => 'comment#listComments', 'url' => '/api/v1/messages/{messageId}/comments', 'verb' => 'GET'],
        ['name' => 'comment#createComment', 'url' => '/api/v1/messages/{messageId}/comments', 'verb' => 'POST'],
        ['name' => 'comment#updateComment', 'url' => '/api/v1/comments/{commentId}', 'verb' => 'PUT'],

        // Widget registry — external-app registration endpoints
        ['name' => 'widget#registerWidget',   'url' => '/api/v1/ext/widgets/register',    'verb' => 'POST'],
        ['name' => 'widget#deregisterWidget', 'url' => '/api/v1/ext/widgets/{appId}',      'verb' => 'DELETE'],

        // Widget — sidebar (fetched on team select)
        ['name' => 'widget#getEnabledWidgets', 'url' => '/api/v1/teams/{teamId}/widgets', 'verb' => 'GET'],

        // Widget — Manage Team → Widgets tab
        ['name' => 'widget#getWidgetRegistry', 'url' => '/api/v1/teams/{teamId}/widget-registry',                         'verb' => 'GET'],
        ['name' => 'widget#toggleWidget',      'url' => '/api/v1/teams/{teamId}/widget-registry/{registryId}/toggle',     'verb' => 'POST'],
        ['name' => 'widget#reorderWidgets',    'url' => '/api/v1/teams/{teamId}/widget-registry/reorder',                 'verb' => 'PUT'],
    ],
];
