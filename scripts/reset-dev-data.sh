#!/usr/bin/env bash
#
# Reset a local dev instance to what a fresh install of this app looks like.
# Wipes every appointment, response, audit event, attachment link, category,
# iCal token and reminder log, plus all app and per-user settings. The app
# stays enabled and its tables stay in place — only the contents go.
#
# DEV ONLY. There is no undo, and it does not ask what instance it is pointed
# at beyond the container names below.
#
#   ./scripts/reset-dev-data.sh          # asks first
#   ./scripts/reset-dev-data.sh -y       # no prompt
#
# Container names come from the env, so a second instance needs no edit:
#   NC_CONTAINER=master-stable32-1 ./scripts/reset-dev-data.sh

set -euo pipefail

NC_CONTAINER="${NC_CONTAINER:-master-stable34-1}"
DB_CONTAINER="${DB_CONTAINER:-master-database-mysql-1}"

occ() { docker exec -u www-data "$NC_CONTAINER" php occ "$@"; }

# Everything ConfigService and PermissionService can write. `enabled`,
# `installed_version` and `types` are deliberately left alone — deleting those
# unregisters the app rather than resetting it.
APP_KEYS=(
	onboarding_completed
	whitelisted_groups whitelisted_teams
	reminders_enabled reminder_days reminder_frequency reminder_target
	calendar_sync_enabled
	org_calendar_enabled org_calendar_uri org_calendar_user_id
	audit_log_enabled audit_log_visibility
	display_order push_enabled push_proxy_server mobile_app_banner_enabled
	booking_enabled self_checkin_window_minutes
)

# Dropping the mode keys is safe even though Version000018 writes them at
# install: getModeForPermission() derives the same values when they are absent.
PERMISSIONS=(
	manage_appointments checkin
	see_response_overview see_response_counts see_comments
	self_checkin create_appointments respond_for_others
	see_statistics
)

TABLES=(
	att_appointments att_responses att_audit_event
	att_attachments att_categories att_ical_tokens att_reminder_log
)

if [ "${1:-}" != "-y" ]; then
	echo "This wipes ALL Attendance data and settings in $NC_CONTAINER / $DB_CONTAINER."
	read -rp "Continue? [y/N] " answer
	[ "$answer" = "y" ] || { echo "Aborted."; exit 1; }
fi

echo "==> Reading database settings from Nextcloud"
DB_NAME=$(occ config:system:get dbname | tr -d '\r')
DB_USER=$(occ config:system:get dbuser | tr -d '\r')
DB_PASS=$(occ config:system:get dbpassword | tr -d '\r')
DB_PREFIX=$(occ config:system:get dbtableprefix 2>/dev/null | tr -d '\r' || echo 'oc_')
DB_PREFIX="${DB_PREFIX:-oc_}"
echo "    database=$DB_NAME prefix=$DB_PREFIX"

echo "==> Deleting app settings"
for key in "${APP_KEYS[@]}"; do
	occ config:app:delete attendance "$key" >/dev/null
done
for permission in "${PERMISSIONS[@]}"; do
	occ config:app:delete attendance "permission_${permission}" >/dev/null
	occ config:app:delete attendance "permission_${permission}_mode" >/dev/null
done

echo "==> Emptying tables and per-user settings"
# The database image may ship either client name.
DB_CLIENT=mysql
docker exec "$DB_CONTAINER" sh -c 'command -v mysql' >/dev/null 2>&1 || DB_CLIENT=mariadb

{
	echo "SET FOREIGN_KEY_CHECKS=0;"
	for table in "${TABLES[@]}"; do
		echo "TRUNCATE TABLE \`${DB_PREFIX}${table}\`;"
	done
	# ical_reminder_triggers and notify_response_changes live here.
	echo "DELETE FROM \`${DB_PREFIX}preferences\` WHERE appid = 'attendance';"
	echo "SET FOREIGN_KEY_CHECKS=1;"
} | docker exec -i -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" "$DB_CLIENT" -u"$DB_USER" "$DB_NAME"

echo "==> Remaining Attendance config (expect only enabled/installed_version/types)"
occ config:list attendance

cat <<'EOF'

Done. Two things this cannot reach:

  * The mobile-app banner hides itself in browser localStorage. Clear the key
    "attendance:mobile-app-banner-dismissed:self-checkin", or open the app in
    a private window.
  * Nextcloud users and groups are untouched, so the group pickers stay
    populated — which is what a fresh install on an existing instance looks
    like anyway.
EOF
