<?php

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		
		// Appointment management routes
		['name' => 'appointment#index', 'url' => '/api/appointments', 'verb' => 'GET'],
		['name' => 'appointment#create', 'url' => '/api/appointments', 'verb' => 'POST'],
		['name' => 'appointment#update', 'url' => '/api/appointments/{id}', 'verb' => 'PUT'],
		['name' => 'appointment#destroy', 'url' => '/api/appointments/{id}', 'verb' => 'DELETE'],
		
		// Attendance response routes
		['name' => 'appointment#respond', 'url' => '/api/appointments/{id}/respond', 'verb' => 'POST'],
		['name' => 'appointment#getResponses', 'url' => '/api/appointments/{id}/responses', 'verb' => 'GET'],
		
		// Dashboard widget route
		['name' => 'appointment#widget', 'url' => '/api/appointments/widget', 'verb' => 'GET'],
		
		// Response checkin routes (admin only)
		['name' => 'appointment#checkinResponse', 'url' => '/api/appointments/{appointmentId}/checkin/{targetUserId}', 'verb' => 'POST'],
	]
];
