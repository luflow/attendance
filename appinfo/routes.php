<?php

return [
	'routes' => [
		// Vue pages
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#unanswered', 'url' => '/unanswered', 'verb' => 'GET'],
		['name' => 'page#past', 'url' => '/past', 'verb' => 'GET'],
		['name' => 'page#appointment', 'url' => '/appointment/{id}', 'verb' => 'GET'],
		['name' => 'page#checkin', 'url' => '/checkin/{id}', 'verb' => 'GET'],
		
		// Appointment management routes
		// NOTE: Specific routes must come BEFORE wildcard {id} routes
		['name' => 'appointment#index', 'url' => '/api/appointments', 'verb' => 'GET'],
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
		
		// Admin settings
		['name' => 'admin#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#saveSettings', 'url' => '/api/admin/settings', 'verb' => 'POST'],

		// User data
		['name' => 'appointment#getPermissions', 'url' => '/api/user/permissions', 'verb' => 'GET'],

		// Search
		['name' => 'appointment#searchUsersAndGroups', 'url' => '/api/search/users-groups', 'verb' => 'GET'],

		// Export
		['name' => 'appointment#export', 'url' => '/api/export', 'verb' => 'POST'],

		// iCal feed
		['name' => 'ical#getToken', 'url' => '/api/ical/token', 'verb' => 'GET'],
		['name' => 'ical#regenerateToken', 'url' => '/api/ical/token/regenerate', 'verb' => 'POST'],
		['name' => 'ical#feed', 'url' => '/ical/{token}.ics', 'verb' => 'GET'],
	]
];
