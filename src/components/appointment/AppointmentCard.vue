<template>
	<div class="appointment-card" data-test="appointment-card">
		<div class="appointment-header" :class="{ 'appointment-header--cancelled': isCancelled }">
			<div class="appointment-title-block">
				<div class="appointment-headline">
					<h3 data-test="appointment-title" :class="{ 'title-cancelled': isCancelled }">
						{{ titleText }}
					</h3>
					<AppointmentStatusChips :appointment="appointment" />
				</div>
				<AppointmentMeta :appointment="appointment" :dateText="subtitleText" />
			</div>
			<AppointmentActionsMenu
				:appointment="appointment"
				:canSeeAuditLog="canSeeAuditLog"
				@startCheckin="emit('startCheckin', $event)"
				@edit="emit('edit', $event)"
				@copy="emit('copy', $event)"
				@delete="emit('delete', $event)"
				@export="emit('export', $event)"
				@showAuditLog="emit('showAuditLog', $event)"
				@closedToggled="emit('closedToggled', $event)" />
		</div>

		<!-- Cancelled banner -->
		<div v-if="isCancelled" class="status-banner status-banner--error" data-test="cancelled-banner">
			<CalendarRemoveIcon :size="20" />
			<div class="status-banner__text">
				<!-- TRANSLATORS: Banner headline on an appointment that was called off (German "Termin abgesagt", not "abgebrochen"). -->
				<strong>{{ t("attendance", "Appointment cancelled") }}</strong>
				<span>{{ formatCancelledLabel(appointment.cancelledAt) }}</span>
			</div>
			<NcButton
				v-if="canCancel"
				variant="secondary"
				:disabled="togglingCancelled"
				data-test="banner-reactivate-appointment"
				@click="toggleCancelled">
				<!-- TRANSLATORS: Button that takes a cancellation back — the appointment will take place again. -->
				{{ t("attendance", "Reactivate") }}
			</NcButton>
		</div>

		<!-- Closed banner — only managers can act on it, everyone else gets the chip -->
		<div v-else-if="isClosed && canManage" class="status-banner" data-test="closed-banner">
			<LockIcon :size="20" />
			<div class="status-banner__text">
				<strong>{{ t("attendance", "Inquiry closed") }}</strong>
				<span>{{ closedLabel }}</span>
			</div>
			<NcButton
				variant="secondary"
				:disabled="togglingClosed"
				data-test="banner-reopen-inquiry"
				@click="toggleClosed">
				{{ t("attendance", "Reopen") }}
			</NcButton>
		</div>

		<!-- eslint-disable vue/no-v-html -- sanitized with DOMPurify -->
		<div
			v-if="appointment.description"
			class="appointment-description"
			v-html="renderedDescription" />
		<!-- eslint-enable vue/no-v-html -->

		<div
			v-if="appointment.attachments?.length"
			class="attachment-chips"
			data-test="attachment-chips">
			<a
				v-for="attachment in appointment.attachments"
				:key="attachment.fileId"
				:href="attachment.downloadUrl || generateUrl(`/f/${attachment.fileId}`)"
				target="_blank"
				rel="noopener noreferrer"
				class="attachment-link"
				:data-test="`attachment-link-${attachment.fileId}`">
				<NcChip :text="attachment.fileName" noClose>
					<template #icon>
						<Paperclip :size="16" />
					</template>
				</NcChip>
			</a>
		</div>

		<!-- Response: editable while the inquiry runs -->
		<div v-if="acceptsResponses" class="card-section" data-test="response-section">
			<h4>{{ t("attendance", "Your response") }}</h4>
			<ResponseEditor
				:appointmentId="appointment.id"
				:userResponse="userResponse"
				:comment="appointment.userResponse?.comment || ''"
				:responseDeadline="appointment.responseDeadline"
				@submitResponse="(id, response) => emit('submitResponse', id, response)" />
		</div>

		<!-- Response: read-only once closed or cancelled -->
		<div v-else class="card-section card-section--readonly" data-test="response-section-readonly">
			<div class="response-row">
				<h4>{{ t("attendance", "Your response") }}</h4>
				<span v-if="userResponse" class="response-row__value">
					<ResponseDot :response="userResponse" />
					<strong>{{ getResponseText(userResponse) }}</strong>
				</span>
				<span v-else class="response-row__value">{{ t("attendance", "No response") }}</span>
			</div>
			<div v-if="scheduleNote" class="schedule-note" data-test="schedule-note">
				{{ scheduleNote }}
			</div>
			<div v-if="isClosed && !canManage" class="closed-info" data-test="closed-info">
				<LockIcon :size="16" />
				<span>{{ closedLabel }}</span>
			</div>
		</div>

		<!-- Check-in summary (only when check-ins exist and the viewer may see them) -->
		<div
			v-if="canSeeResponses && appointment.checkinSummary?.hasCheckins"
			class="card-section"
			data-test="checkin-summary">
			<h4>{{ t("attendance", "Check-in summary") }}</h4>
			<ResponseBar :segments="checkinSegments" />
		</div>

		<ResponseSummary
			v-if="canSeeResponses && appointment.responseSummary"
			:responseSummary="appointment.responseSummary"
			:canSeeComments="canSeeComments"
			:canManageAppointments="canManage"
			:appointmentId="appointment.id"
			:isClosed="isClosed"
			:acceptsResponses="acceptsResponses"
			@refreshAppointment="emit('refreshAppointment')" />
	</div>
</template>

<script setup>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcChip } from '@nextcloud/vue'
import { computed } from 'vue'
import CalendarRemoveIcon from 'vue-material-design-icons/CalendarRemove.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import AppointmentActionsMenu from './AppointmentActionsMenu.vue'
import AppointmentMeta from './AppointmentMeta.vue'
import AppointmentStatusChips from './AppointmentStatusChips.vue'
import ResponseBar from './ResponseBar.vue'
import ResponseDot from './ResponseDot.vue'
import ResponseEditor from './ResponseEditor.vue'
import ResponseSummary from './ResponseSummary.vue'
import { useAppointmentCard } from '../../composables/useAppointmentCard.js'
import { useAppointmentLifecycle } from '../../composables/useAppointmentLifecycle.js'
import { finalScheduleStatus, formatCancelledLabel, formatClosedLabel } from '../../utils/appointment.js'
import { formatTime } from '../../utils/datetime.js'
import { renderMarkdown, sanitizeHtml } from '../../utils/markdown.js'
import { getResponseText } from '../../utils/response.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
})

const emit = defineEmits([
	'startCheckin',
	'edit',
	'copy',
	'delete',
	'export',
	'submitResponse',
	'closedToggled',
	'showAuditLog',
	'refreshAppointment',
])

// NB: pass a getter, never bind it to a name — every top-level binding in
// <script setup> is exposed to the template, and a local `appointment` would
// shadow `props.appointment` there with the function itself.
const {
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
} = useAppointmentCard(() => props.appointment)

// The banners offer the reverse of the menu's destructive actions, so they
// drive the same lifecycle logic — never the confirmation dialog, which only
// guards closing.
const { canCancel, togglingClosed, togglingCancelled, toggleClosed, toggleCancelled }
	= useAppointmentLifecycle(() => props.appointment, { onUpdated: (updated) => emit('closedToggled', updated) })

const closedLabel = computed(() => formatClosedLabel(props.appointment.closedAt, props.appointment.responseDeadline))

// Once the inquiry is closed the scheduling verdict is final — spell it out
// below the read-only answer, the header chip alone is easy to misread.
const scheduleNote = computed(() => {
	const status = finalScheduleStatus(props.appointment, capabilities.bookingEnabled)
	if (status === 'booked') {
		// TRANSLATORS: Note under the user's own answer on a closed inquiry — they got a place in the appointment (German "eingeplant"). {when} is a time of day only, e.g. "10:00" or "10:00 AM", never a date; the date is shown in the appointment header above.
		return t('attendance', 'You are scheduled in for this appointment. Please be there at {when}.', {
			when: formatTime(props.appointment.startDatetime),
		})
	}
	if (status === 'declined') {
		// TRANSLATORS: Note under the user's own answer on a closed inquiry, shown to somebody who volunteered but did NOT get a place because the organizer had already scheduled in enough people. "scheduled in" is the same act as in the sibling string "You are scheduled in for this appointment." The thanks is for answering at all, not for the answer being yes. German: "Für diesen Termin sind bereits genug Leute eingeplant — danke für deine Rückmeldung!"
		return t('attendance', 'Enough people are already scheduled in for this appointment — thanks for your response!')
	}
	return ''
})

// One computed instead of three: the string extractor only binds a TRANSLATORS
// comment to a t() call on the very next line, which rules out inline ternaries
// and one-expression arrow bodies.
const checkinSegments = computed(() => {
	const summary = props.appointment.checkinSummary ?? {}
	// TRANSLATORS: Legend under the check-in bar, rendered directly after the number as "5 attended" — so keep it lowercase in languages that do not capitalize mid-sentence, and do not repeat the count. Means physically present at the event, not "signed up". Sibling labels: "absent", "pending". All three describe the current check-in state of the same event, so translate as status labels rather than strict past/present tense.
	const attended = t('attendance', 'attended')
	// TRANSLATORS: Legend under the check-in bar, rendered directly after the number as "2 absent" — the person was expected but explicitly marked absent. Same lowercase and no-count rules as "attended".
	const absent = t('attendance', 'absent')
	// TRANSLATORS: Legend under the check-in bar, rendered directly after the number as "7 pending" — no check-in recorded for the person yet. Same lowercase and no-count rules as "attended".
	const pending = t('attendance', 'pending')
	return [
		{ key: 'attended', variant: 'success', count: summary.attended ?? 0, label: attended },
		{ key: 'absent', variant: 'error', count: summary.absent ?? 0, label: absent },
		{ key: 'pending', variant: 'tertiary', count: summary.notCheckedIn ?? 0, label: pending },
	]
})

const renderedDescription = computed(() => {
	if (!props.appointment.description) return ''
	return sanitizeHtml(renderMarkdown(props.appointment.description, false))
})

</script>

<style scoped lang="scss">
@use "../../styles/shared.scss";

.appointment-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;
    margin-bottom: 20px;
}

.appointment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    background: var(--color-background-hover);
    margin: -20px -20px 20px -20px;
    padding: 20px;
    border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;

    &--cancelled {
        background: rgba(var(--color-error-rgb), 0.12);
    }

    .appointment-title-block {
        flex: 1;
        min-width: 0;

        .appointment-headline {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            // The chips sit next to the heading, not inside it, so the row sets
            // the size they inherit and the h3 overrides it for itself.
            font-size: 13px;
            margin-bottom: 4px;
        }

        h3 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 700;
            color: var(--color-main-text);
            text-wrap: pretty;
        }

    }
}

.status-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    margin-bottom: 16px;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
    border: 1px solid var(--color-border);

    &--error {
        background: rgba(var(--color-error-rgb), 0.12);
        border-color: var(--color-error);

        strong {
            color: var(--color-error-text);
        }
    }

    &__text {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 2px;

        strong {
            font-weight: 600;
        }

        span {
            font-size: 0.85em;
            color: var(--color-text-maxcontrast);
        }
    }
}

.card-section {
    border-top: 1px solid var(--color-border);
    padding-top: 15px;
    margin-top: 15px;

    h4 {
        font-size: 1.2em;
        margin: 0 0 10px 0;
    }

    &--readonly {
        display: flex;
        flex-direction: column;
        gap: 8px;

        h4 {
            margin: 0;
        }
    }
}

.response-row {
    display: flex;
    align-items: center;
    gap: 12px;

    &__value {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
}

.schedule-note,
.closed-info {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.attachment-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 15px;

    .attachment-link {
        text-decoration: none;
        color: inherit;

        &:hover :deep(.nc-chip) {
            background-color: var(--color-background-hover);
        }
    }
}

.appointment-description {
    color: var(--color-text-maxcontrast);
    margin-bottom: 15px;

    // Markdown formatting
    :deep(strong) {
        font-weight: bold;
        color: var(--color-main-text);
    }

    :deep(em) {
        font-style: italic;
    }

    :deep(del) {
        text-decoration: line-through;
    }

    :deep(a) {
        color: var(--color-primary-element);
        text-decoration: none;

        &:hover {
            text-decoration: underline;
        }
    }

    :deep(code) {
        background-color: var(--color-background-dark);
        padding: 2px 6px;
        border-radius: var(--border-radius-small);
        font-family: monospace;
        font-size: 0.9em;
    }

    :deep(pre) {
        background-color: var(--color-background-dark);
        padding: 12px;
        border-radius: var(--border-radius);
        overflow-x: auto;
        margin: 10px 0;

        code {
            background: none;
            padding: 0;
        }
    }

    :deep(blockquote) {
        border-left: 3px solid var(--color-primary-element);
        margin: 10px 0;
        padding-left: 15px;
        color: var(--color-text-maxcontrast);
    }

    :deep(ul) {
        margin: 10px 0;
        padding-left: 25px;
        list-style-type: disc;
    }

    :deep(ol) {
        margin: 10px 0;
        padding-left: 25px;
        list-style-type: decimal;
    }

    :deep(li) {
        margin: 5px 0;
        display: list-item;
    }

    :deep(h1),
    :deep(h2),
    :deep(h3),
    :deep(h4),
    :deep(h5),
    :deep(h6) {
        color: var(--color-main-text);
        margin: 15px 0 10px 0;
        font-weight: 600;
    }

    :deep(h1) {
        font-size: 1.75em;
        font-weight: 700;
    }
    :deep(h2) {
        font-size: 1.45em;
    }
    :deep(h3) {
        font-size: 1.2em;
    }
    :deep(h4) {
        font-size: 1.05em;
    }

    :deep(hr) {
        border: none;
        border-top: 1px solid var(--color-border);
        margin: 15px 0;
    }

    :deep(table) {
        border-collapse: collapse;
        width: 100%;
        margin: 10px 0;
    }

    :deep(th),
    :deep(td) {
        border: 1px solid var(--color-border);
        padding: 8px 12px;
        text-align: left;
    }

    :deep(th) {
        background-color: var(--color-background-dark);
        font-weight: 600;
    }

    :deep(p) {
        margin: 10px 0;

        &:first-child {
            margin-top: 0;
        }

        &:last-child {
            margin-bottom: 0;
        }
    }

    :deep(img) {
        max-width: 100%;
        height: auto;
        border-radius: var(--border-radius);
    }
}
</style>
