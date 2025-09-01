# Attendance

A Nextcloud app for managing event attendance with advance RSVP functionality. Administrators can create appointments and track responses, while users can easily indicate their attendance status and optional comment on their attendance.

## Features

### For Administrators
- **Create & Manage Appointments:** Set up events with name, description, and date/time
- **Track Responses:** View detailed attendance summaries organized by user groups
- **See Non-Responders:** Identify who hasn't responded yet to follow up
- **Flexible Views:** Toggle between upcoming and past appointments
- **Check-in Management:** Track actual attendance during events with dedicated check-in interface
- **Bulk Operations:** Mark multiple users as present/absent with bulk check-in actions
- **Admin Comments:** Add check-in specific comments for attendance tracking
- **Group Whitelisting:** Configure which user groups are included in attendance statistics and check-in lists

### For All Users  
- **Easy RSVP:** Respond with Yes/No/Maybe to any appointment
- **Add Comments:** Include optional notes with your response (especially for Maybe/No responses)
- **Update Anytime:** Change your response until the event date
- **Dashboard Widget:** Quick access to upcoming appointments directly from your dashboard
- **Check-in View:** Dedicated interface for administrators to track actual attendance during events

### Group-Based Organization
- **Group Summaries:** Responses are automatically organized by nextcloud user groups
- **Missing Responses:** See which user group members haven't responded yet
- **Admin Overview:** Complete visibility into attendance across all groups
- **Filtered Check-in:** Search and filter users by name or group during check-in process

## Installation

1. Place this app in **nextcloud/apps/**
2. Enable the app in Nextcloud admin settings
3. The database tables will be created automatically via migration

