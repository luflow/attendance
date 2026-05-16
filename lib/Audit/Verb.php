<?php

declare(strict_types=1);

namespace OCA\Attendance\Audit;

/**
 * Catalogue of audit event verbs. Stored as strings in att_audit_event.verb
 * so the table can absorb future verbs without schema changes.
 */
final class Verb {
	public const RESPONSE_SUBMITTED = 'response.submitted';
	public const RESPONSE_CHANGED = 'response.changed';
	public const RESPONSE_RESCINDED = 'response.rescinded';
	public const RESPONSE_COMMENT_UPDATED = 'response.comment_updated';

	public const CHECKIN_RECORDED = 'checkin.recorded';
	public const CHECKIN_CHANGED = 'checkin.changed';

	public const ALL_RESPONSE = [
		self::RESPONSE_SUBMITTED,
		self::RESPONSE_CHANGED,
		self::RESPONSE_RESCINDED,
		self::RESPONSE_COMMENT_UPDATED,
	];

	public const ALL_CHECKIN = [
		self::CHECKIN_RECORDED,
		self::CHECKIN_CHANGED,
	];

	public const ALL = [
		self::RESPONSE_SUBMITTED,
		self::RESPONSE_CHANGED,
		self::RESPONSE_RESCINDED,
		self::RESPONSE_COMMENT_UPDATED,
		self::CHECKIN_RECORDED,
		self::CHECKIN_CHANGED,
	];

	public const SOURCE_APP = 'app';
	public const SOURCE_QUICK_LINK = 'quick_link';
	public const SOURCE_ADMIN_CHECKIN = 'admin_checkin';
	public const SOURCE_LEGACY_BACKFILL = 'legacy_backfill';

	public const ANONYMISED_USER = '__deleted_user__';
}
