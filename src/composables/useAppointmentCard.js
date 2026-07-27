/**
 * The derivation layer both appointment cards share.
 *
 * The detail card and the compact list card render completely different
 * layouts, but they answer the same questions first: what state is this
 * appointment in, what may the viewer do with it, and what goes in the title.
 * Keeping that in one place is what lets the two templates diverge freely.
 */

import { computed, unref } from 'vue'
import { formatDateRange } from '../utils/datetime.js'
import { usePermissions } from './usePermissions.js'

/**
 * @param {object|import('vue').Ref<object>} appointmentSource The appointment
 *        payload, or a ref/getter for it.
 * @return {object} Reactive state shared by both cards.
 */
export function useAppointmentCard(appointmentSource) {
	const { capabilities, config, permissions } = usePermissions()

	const appointment = computed(() => (typeof appointmentSource === 'function'
		? appointmentSource()
		: unref(appointmentSource)))

	const isClosed = computed(() => Boolean(appointment.value.closedAt))
	const isCancelled = computed(() => Boolean(appointment.value.cancelledAt))

	// Neither a closed inquiry nor a called-off appointment takes new answers.
	const acceptsResponses = computed(() => !isClosed.value && !isCancelled.value)

	const userResponse = computed(() => appointment.value.userResponse?.response || null)

	// Per-appointment verdicts from the server; the instance-wide permissions
	// act as fallback so older payloads (without myPermissions) keep working.
	const myPermissions = computed(() => appointment.value.myPermissions || {})
	const canManage = computed(() => permissions.canManageAppointments || myPermissions.value.canEdit === true)
	const canSeeResponses = computed(() => permissions.canSeeResponseOverview || myPermissions.value.canSeeResponses === true)
	const canSeeComments = computed(() => permissions.canSeeComments || myPermissions.value.canSeeComments === true)
	const canSeeAuditLog = computed(() => capabilities.auditLog
		&& (myPermissions.value.canSeeAuditLog === true
			|| permissions.canManageAppointments
			|| permissions.canSeeResponseOverview))

	// Users can swap which of name and date leads the card.
	const dateRange = computed(() => formatDateRange(appointment.value.startDatetime, appointment.value.endDatetime))
	const dateFirst = computed(() => config.displayOrder === 'date_first')
	const titleText = computed(() => (dateFirst.value ? dateRange.value : appointment.value.name))
	const subtitleText = computed(() => (dateFirst.value ? appointment.value.name : dateRange.value))

	return {
		capabilities,
		isClosed,
		isCancelled,
		acceptsResponses,
		userResponse,
		canManage,
		canSeeResponses,
		canSeeComments,
		canSeeAuditLog,
		titleText,
		subtitleText,
	}
}
