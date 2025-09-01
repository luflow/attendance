<?php

return [
	'routes' => [
		// Vue pages
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#checkin', 'url' => '/checkin/{id}', 'verb' => 'GET'],
		
		// Appointment management routes
		['name' => 'appointment#index', 'url' => '/api/appointments', 'verb' => 'GET'],
		['name' => 'appointment#create', 'url' => '/api/appointments', 'verb' => 'POST'],
		['name' => 'appointment#update', 'url' => '/api/appointments/{id}', 'verb' => 'PUT'],
		['name' => 'appointment#destroy', 'url' => '/api/appointments/{id}', 'verb' => 'DELETE'],

		// Dashboard widget route
		['name' => 'appointment#widget', 'url' => '/api/appointments/widget', 'verb' => 'GET'],
				
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
	]
];
