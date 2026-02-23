<?php

return [
	'routes' => [
		// Vue pages
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#unanswered', 'url' => '/unanswered', 'verb' => 'GET'],
		['name' => 'page#past', 'url' => '/past', 'verb' => 'GET'],
		['name' => 'page#appointment', 'url' => '/appointment/{id}', 'verb' => 'GET'],
		['name' => 'page#checkin', 'url' => '/checkin/{id}', 'verb' => 'GET'],
		['name' => 'page#create', 'url' => '/create', 'verb' => 'GET'],
		['name' => 'page#edit', 'url' => '/edit/{id}', 'verb' => 'GET'],
		['name' => 'page#copy', 'url' => '/copy/{id}', 'verb' => 'GET'],

		// Appointment management routes
		// NOTE: Specific routes must come BEFORE wildcard {id} routes
		['name' => 'appointment#index', 'url' => '/api/appointments', 'verb' => 'GET'],
		['name' => 'appointment#bulkCreate', 'url' => '/api/appointments/bulk', 'verb' => 'POST'],
		['name' => 'appointment#navigation', 'url' => '/api/appointments/navigation', 'verb' => 'GET'],
		['name' => 'appointment#widget', 'url' => '/api/appointments/widget', 'verb' => 'GET'],
		['name' => 'appointment#show', 'url' => '/api/appointments/{id}', 'verb' => 'GET'],
		['name' => 'appointment#create', 'url' => '/api/appointments', 'verb' => 'POST'],
		['name' => 'appointment#update', 'url' => '/api/appointments/{id}', 'verb' => 'PUT'],
		['name' => 'appointment#destroy', 'url' => '/api/appointments/{id}', 'verb' => 'DELETE'],

		// Attendance response routes
		['name' => 'appointment#respond', 'url' => '/api/appointments/{id}/respond', 'verb' => 'POST'],
		['name' => 'appointment#getResponses', 'url' => '/api/appointments/{id}/responses', 'verb' => 'GET'],

		// Check-in functionality routes
		['name' => 'appointment#checkinResponse', 'url' => '/api/appointments/{appointmentId}/checkin/{targetUserId}', 'verb' => 'POST'],
		['name' => 'appointment#getCheckinData', 'url' => '/api/appointments/{id}/checkin-data', 'verb' => 'GET'],
		['name' => 'appointment#resetCheckin', 'url' => '/api/appointments/{id}/checkin-reset', 'verb' => 'DELETE'],

		// Admin settings
		['name' => 'admin#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#saveSettings', 'url' => '/api/admin/settings', 'verb' => 'POST'],

		// User data
		['name' => 'appointment#getPermissions', 'url' => '/api/user/permissions', 'verb' => 'GET'],

		// Search
		['name' => 'appointment#searchUsersGroupsTeams', 'url' => '/api/search/users-groups-teams', 'verb' => 'GET'],

		// Export
		['name' => 'appointment#export', 'url' => '/api/export', 'verb' => 'POST'],

		// iCal feed
		['name' => 'ical#getToken', 'url' => '/api/ical/token', 'verb' => 'GET'],
		['name' => 'ical#regenerateToken', 'url' => '/api/ical/token/regenerate', 'verb' => 'POST'],
		['name' => 'ical#feed', 'url' => '/ical/{token}.ics', 'verb' => 'GET'],

		// Calendar integration (for importing events)
		['name' => 'calendar#isAvailable', 'url' => '/api/calendar/available', 'verb' => 'GET'],
		['name' => 'calendar#getCalendars', 'url' => '/api/calendar/calendars', 'verb' => 'GET'],
		['name' => 'calendar#getEvents', 'url' => '/api/calendar/events', 'verb' => 'GET'],

		// Quick response (public endpoints for email/notification links)
		['name' => 'quick_response#showConfirmation', 'url' => '/respond/{appointmentId}', 'verb' => 'GET'],
		['name' => 'quick_response#confirmResponse', 'url' => '/respond/{appointmentId}/confirm', 'verb' => 'POST'],
	]
];
