<template>
	<div class="appointment-form-view" data-test="appointment-form-view">
		<div class="form-header">
			<div class="header-actions">
				<NcButton
					variant="tertiary"
					data-test="button-back"
					@click="goBack">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t("attendance", "Back") }}
				</NcButton>
				<NcButton
					v-if="mode === 'create' && props.calendarAvailable"
					variant="tertiary"
					data-test="button-import-calendar"
					@click="showCalendarPicker = true">
					<template #icon>
						<CalendarImport :size="20" />
					</template>
					{{ t("attendance", "Import from calendar") }}
				</NcButton>
			</div>
			<h2 data-test="form-title">
				{{ pageTitle }}
			</h2>
		</div>

		<div v-if="loading || bulkImporting" class="loading-state">
			<NcLoadingIcon v-if="bulkImporting" :size="32" />
			{{
				bulkImporting
					? t("attendance", "Importing appointments\u00A0…")
					: t("attendance", "Loading\u00A0…")
			}}
		</div>

		<form
			v-else
			class="appointment-form"
			data-test="appointment-form"
			@submit.prevent="handleSubmit">
			<!-- Calendar Link Info (shown when linked to calendar, not for copy mode) -->
			<div
				v-if="hasCalendarReference && mode !== 'copy'"
				class="form-section">
				<div class="calendar-link-header">
					<LinkVariant :size="20" />
					<h3>{{ t("attendance", "Linked to calendar") }}</h3>
					<a
						:href="calendarSyncSettingsUrl"
						target="_blank"
						class="auto-sync-chip-link">
						<NcChip
							v-if="props.calendarSyncEnabled"
							type="success"
							:text="t('attendance', 'Auto-sync enabled')"
							no-close>
							<template #icon>
								<CalendarSync :size="16" />
							</template>
						</NcChip>
					</a>
				</div>
				<p class="hint-text">
					{{
						t(
							"attendance",
							"This appointment is linked to a calendar event.",
						)
					}}
					<template v-if="props.calendarSyncEnabled">
						{{
							t(
								"attendance",
								"Changes to the calendar event will automatically overwrite title, description, and date/time of this appointment.",
							)
						}}
					</template>
					<template v-else>
						{{
							t(
								"attendance",
								"Changes to the calendar event will not be synced.",
							)
						}}
						<a :href="calendarSyncSettingsUrl" target="_blank">{{
							t("attendance", "Enable auto-sync")
						}}</a>
					</template>
				</p>
			</div>

			<!-- Series Info (shown when appointment is part of a series) -->
			<div
				v-if="seriesId && mode === 'edit'"
				class="form-section">
				<div class="series-info-header">
					<RepeatIcon :size="20" />
					<h3>{{ t("attendance", "Part of a recurring series") }}</h3>
				</div>
				<p class="hint-text">
					{{
						t(
							"attendance",
							"This appointment is part of a series. When saving, you can choose to apply changes to this appointment only, this and future appointments, or all appointments in the series.",
						)
					}}
				</p>
			</div>

			<div class="form-section">
				<NcTextField
					v-model="formData.name"
					:label="t('attendance', 'Appointment name')"
					data-test="input-appointment-name" />

				<MarkdownEditor
					v-model="formData.description"
					:label="t('attendance', 'Description')"
					:placeholder="
						t('attendance', 'Write your description here\u00A0…')
					"
					data-test="input-appointment-description"
					min-height="150px" />
			</div>

			<div class="form-section">
				<h3>{{ t("attendance", "Date & Time") }}</h3>
				<div class="datetime-fields">
					<NcDateTimePickerNative
						id="start-datetime"
						:model-value="startDateObject"
						type="datetime-local"
						:label="t('attendance', 'Start date & time')"
						data-test="input-start-datetime"
						@update:model-value="onStartDatetimeChange"
						@blur="onStartDatetimeBlur" />

					<NcDateTimePickerNative
						id="end-datetime"
						:model-value="endDateObject"
						type="datetime-local"
						:label="t('attendance', 'End date & time')"
						data-test="input-end-datetime"
						@update:model-value="onEndDatetimeChange" />
				</div>

				<RecurrenceSelector
					v-if="mode === 'create'"
					:start-date="startDateObject"
					:duration="appointmentDuration"
					:disabled="saving"
					data-test="recurrence-selector"
					@update:occurrences="onRecurrenceUpdate"
					@update:validation-warning="onRecurrenceWarningUpdate" />
			</div>

			<div class="form-section">
				<h3>{{ t("attendance", "Response deadline") }}</h3>
				<p class="hint-text">
					{{
						t(
							"attendance",
							"After this date, the inquiry is automatically closed and no further responses are accepted. Reminders are scheduled relative to the deadline.",
						)
					}}
				</p>
				<div class="deadline-mode-row" data-test="deadline-mode-row">
					<NcCheckboxRadioSwitch
						v-model="deadlineMode"
						value="none"
						name="deadline-mode"
						type="radio"
						data-test="deadline-mode-none">
						{{ t("attendance", "No deadline") }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="deadlineMode"
						value="relative"
						name="deadline-mode"
						type="radio"
						data-test="deadline-mode-relative">
						{{ t("attendance", "Relative to start") }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="deadlineMode"
						value="absolute"
						name="deadline-mode"
						type="radio"
						data-test="deadline-mode-absolute">
						{{ t("attendance", "Specific date and time") }}
					</NcCheckboxRadioSwitch>
				</div>
				<div
					v-if="deadlineMode === 'relative'"
					class="deadline-relative-row"
					data-test="deadline-relative-row">
					<NcTextField
						v-model="deadlineRelativeValueStr"
						type="number"
						min="1"
						class="deadline-relative-value"
						:label="t('attendance', 'Number')"
						data-test="input-deadline-relative-value" />
					<select
						v-model="deadlineRelativeUnit"
						class="deadline-relative-unit"
						data-test="select-deadline-relative-unit"
						:aria-label="t('attendance', 'Unit')">
						<option
							v-for="opt in deadlineUnitOptions"
							:key="opt.value"
							:value="opt.value">
							{{ opt.label }}
						</option>
					</select>
					<span class="deadline-relative-suffix">{{
						t("attendance", "before each appointment starts")
					}}</span>
				</div>
				<div
					v-if="deadlineMode === 'absolute'"
					class="deadline-field"
					data-test="deadline-absolute-row">
					<NcDateTimePickerNative
						id="response-deadline"
						:model-value="deadlineAbsoluteDateObject"
						type="datetime-local"
						:label="t('attendance', 'Response deadline')"
						data-test="input-response-deadline"
						@update:model-value="onDeadlineAbsoluteChange" />
				</div>
				<NcCheckboxRadioSwitch
					v-if="deadlineMode === 'absolute' && isRecurring"
					v-model="deadlineAbsoluteLiteral"
					type="switch"
					data-test="deadline-absolute-literal">
					{{
						t(
							"attendance",
							"Apply this exact deadline to every appointment",
						)
					}}
				</NcCheckboxRadioSwitch>
				<p
					v-if="deadlineWarning"
					class="hint-text deadline-warning"
					data-test="deadline-warning">
					{{ deadlineWarning }}
				</p>
			</div>

			<div
				v-if="notificationsAppEnabled && (mode === 'create' || mode === 'copy')"
				class="form-section">
				<h3>{{ t("attendance", "Notification") }}</h3>
				<p class="hint-text">
					{{
						t(
							"attendance",
							"Notify users who can see this appointment about its creation",
						)
					}}
				</p>
				<NcCheckboxRadioSwitch
					v-model="sendNotification"
					data-test="checkbox-send-notification">
					{{ t("attendance", "Send notification") }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="form-section">
				<h3>{{ t("attendance", "Attachments") }}</h3>
				<p class="hint-text">
					{{
						t(
							"attendance",
							"Files that are important for this appointment can be selected here.",
						)
					}}
				</p>
				<div
					v-if="attachments.length > 0"
					class="attachment-list"
					data-test="attachment-list">
					<NcChip
						v-for="attachment in attachments"
						:key="attachment.fileId"
						:text="attachment.fileName"
						:data-test="`attachment-chip-${attachment.fileId}`"
						@close="removeAttachment(attachment.fileId)">
						<template #icon>
							<Paperclip :size="16" />
						</template>
					</NcChip>
				</div>
				<NcButton
					variant="secondary"
					native-type="button"
					data-test="button-add-attachment"
					@click.stop.prevent="openFilePicker">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t("attendance", "Add from Files") }}
				</NcButton>
			</div>

			<div class="form-section">
				<h3>{{ t("attendance", "Restrict access") }}</h3>
				<p class="hint-text">
					{{
						t(
							"attendance",
							"Limits who can see this appointment. Leave empty for all users.",
						)
					}}
				</p>
				<NcSelect
					v-model="visibilityItems"
					:options="searchResults"
					:loading="isSearching"
					:multiple="true"
					:close-on-select="false"
					:filterable="false"
					label="label"
					:placeholder="
						t('attendance', 'Search users, groups or teams\u00A0…')
					"
					data-test="select-visibility"
					@search="onSearch">
					<template #option="{ label, type, isGuest }">
						<span
							style="display: flex; align-items: center; gap: 8px"
							:title="getTypeLabel(type, isGuest)">
							<AccountStar v-if="type === 'team'" :size="20" />
							<AccountGroup
								v-else-if="type === 'group'"
								:size="20" />
							<AccountPlus
								v-else-if="type === 'create-guest'"
								:size="20" />
							<AccountQuestion
								v-else-if="isGuest"
								:size="20" />
							<Account v-else :size="20" />
							<span>{{ label }}</span>
						</span>
					</template>
					<template #selected-option="{ label, type, isGuest }">
						<span
							style="display: flex; align-items: center; gap: 8px"
							:title="getTypeLabel(type, isGuest)">
							<AccountStar v-if="type === 'team'" :size="16" />
							<AccountGroup
								v-else-if="type === 'group'"
								:size="16" />
							<AccountQuestion
								v-else-if="isGuest"
								:size="16" />
							<Account v-else :size="16" />
							<span>{{ label }}</span>
						</span>
					</template>
				</NcSelect>
				<p v-if="guestInvitationAvailable" class="guest-invite-hint">
					<AccountPlus :size="14" />
					{{ t('attendance', 'Enter an email address to invite a guest without a Nextcloud account.') }}
				</p>
				<NcNoteCard
					v-if="hasTrackingMismatch"
					type="warning"
					class="visibility-warning">
					{{
						t(
							"attendance",
							'Some users may appear in the section "Others" in the response summary because they are not configured for tracking.',
						)
					}}
					<a :href="adminSettingsUrl" target="_blank">{{
						t("attendance", "Configure in administration settings")
					}}</a>
				</NcNoteCard>
			</div>

			<div class="form-actions">
				<NcButton
					variant="secondary"
					data-test="button-cancel"
					@click="goBack">
					{{ t("attendance", "Cancel") }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving"
					data-test="button-save"
					@click="handleSubmit">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ saveButtonLabel }}
				</NcButton>
			</div>
		</form>

		<!-- Calendar Event Picker Modal -->
		<CalendarEventPicker
			:show="showCalendarPicker"
			@close="showCalendarPicker = false"
			@select="handleCalendarEventSelect"
			@bulk-select="handleBulkImport" />

		<!-- Series Action Dialog (for edit mode) -->
		<SeriesActionDialog
			:show="showSeriesDialog"
			action="edit"
			:series-count="seriesCount"
			@confirm="handleSeriesEditConfirm"
			@cancel="showSeriesDialog = false" />
	</div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import {
	NcButton,
	NcTextField,
	NcSelect,
	NcNoteCard,
	NcCheckboxRadioSwitch,
	NcChip,
	NcLoadingIcon,
	NcDateTimePickerNative,
} from '@nextcloud/vue'
import {
	getFilePickerBuilder,
	showSuccess,
	showError,
} from '@nextcloud/dialogs'
import MarkdownEditor from '../components/common/MarkdownEditor.vue'
import CalendarEventPicker from '../components/calendar/CalendarEventPicker.vue'
import RecurrenceSelector from '../components/appointment/RecurrenceSelector.vue'
import { generateUrl } from '@nextcloud/router'
import { subscribe as subscribeToEvent, unsubscribe as unsubscribeFromEvent } from '@nextcloud/event-bus'
import axios from '@nextcloud/axios'
import { usePermissions } from '../composables/usePermissions.js'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Account from 'vue-material-design-icons/Account.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountQuestion from 'vue-material-design-icons/AccountQuestion.vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CalendarImport from 'vue-material-design-icons/CalendarImport.vue'
import CalendarSync from 'vue-material-design-icons/CalendarSync.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import SeriesActionDialog from '../components/appointment/SeriesActionDialog.vue'
import '@nextcloud/dialogs/style.css'

const props = defineProps({
	mode: {
		type: String,
		required: true,
		validator: (value) => ['create', 'edit', 'copy'].includes(value),
	},
	appointmentId: {
		type: Number,
		default: null,
	},
	notificationsAppEnabled: {
		type: Boolean,
		default: false,
	},
	calendarAvailable: {
		type: Boolean,
		default: false,
	},
	calendarSyncEnabled: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['saved', 'cancelled'])

// Start with loading=true for edit/copy modes to prevent form flicker
const loading = ref(props.mode === 'edit' || props.mode === 'copy')
const saving = ref(false)

const formData = reactive({
	name: '',
	description: '',
	startDatetime: '',
	endDatetime: '',
	visibleUsers: [],
	visibleGroups: [],
	visibleTeams: [],
})

// Deadline UI is split into three modes that the user toggles between:
//   'none'     → no deadline
//   'relative' → integer + unit, applied per-occurrence (start − offset)
//   'absolute' → a specific datetime; for series/recurring, an extra toggle
//                decides whether the picked datetime is used literally for
//                every occurrence or rebased per-occurrence.
const deadlineMode = ref('none')
const deadlineRelativeValueStr = ref('1')
const deadlineRelativeUnit = ref('days')
const deadlineAbsolute = ref('') // datetime-local string
const deadlineAbsoluteLiteral = ref(true)
const hadDeadlineInitially = ref(false)

const UNIT_MS = {
	minutes: 60 * 1000,
	hours: 60 * 60 * 1000,
	days: 24 * 60 * 60 * 1000,
	weeks: 7 * 24 * 60 * 60 * 1000,
}

const deadlineUnitOptions = computed(() => [
	{ value: 'minutes', label: t('attendance', 'minutes') },
	{ value: 'hours', label: t('attendance', 'hours') },
	{ value: 'days', label: t('attendance', 'days') },
	{ value: 'weeks', label: t('attendance', 'weeks') },
])

const deadlineRelativeValue = computed(() => {
	const n = Number(deadlineRelativeValueStr.value)
	return Number.isFinite(n) && n >= 0 ? Math.floor(n) : 0
})

const deadlineRelativeOffsetMs = computed(
	() => deadlineRelativeValue.value * UNIT_MS[deadlineRelativeUnit.value],
)

const { capabilities, loadPermissions } = usePermissions()

const visibilityItems = ref([])
const searchResults = ref([])
const isSearching = ref(false)
const guestInvitationAvailable = computed(() => capabilities.guestInvitation === true)

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const isEmailAddress = (value) => typeof value === 'string' && EMAIL_REGEX.test(value.trim())
const sendNotification = ref(false)
const trackingGroups = ref([])
const trackingTeams = ref([])
const attachments = ref([])
const showCalendarPicker = ref(false)
const calendarReference = ref({ calendarUri: null, calendarEventUid: null })
const recurrenceOccurrences = ref([])
const recurrenceWarning = ref(null)
const isRecurring = computed(() => recurrenceOccurrences.value.length > 1)
const seriesId = ref(null)
const seriesCount = ref(0)
const showSeriesDialog = ref(false)

const appointmentDuration = computed(() => {
	if (!formData.startDatetime || !formData.endDatetime) return 0
	const start = new Date(formData.startDatetime)
	const end = new Date(formData.endDatetime)
	if (isNaN(start.getTime()) || isNaN(end.getTime())) return 0
	return end.getTime() - start.getTime()
})

const onRecurrenceUpdate = (occurrences) => {
	recurrenceOccurrences.value = occurrences
}

const onRecurrenceWarningUpdate = (warning) => {
	recurrenceWarning.value = warning
}

const saveButtonLabel = computed(() => {
	if (isRecurring.value) {
		return n(
			'attendance',
			'Create {count} appointment',
			'Create {count} appointments',
			recurrenceOccurrences.value.length,
			{ count: recurrenceOccurrences.value.length },
		)
	}
	return t('attendance', 'Save')
})

const pageTitle = computed(() => {
	switch (props.mode) {
	case 'edit':
		return t('attendance', 'Edit appointment')
	case 'copy':
		return t('attendance', 'Copy appointment')
	default:
		return t('attendance', 'Create appointment')
	}
})

const hasTrackingMismatch = computed(() => {
	// No selections at all - no warning needed
	if (
		formData.visibleGroups.length === 0
        && formData.visibleUsers.length === 0
        && formData.visibleTeams.length === 0
	) {
		return false
	}
	// No tracking groups AND no tracking teams configured - no warning needed
	if (trackingGroups.value.length === 0 && trackingTeams.value.length === 0) {
		return false
	}
	// Individual users selected - they might appear under "Others"
	if (formData.visibleUsers.length > 0) {
		return true
	}
	// Check if any selected team is NOT in tracking teams
	if (formData.visibleTeams.length > 0) {
		const hasNonTrackingTeam = formData.visibleTeams.some(
			(teamId) => !trackingTeams.value.includes(teamId),
		)
		if (hasNonTrackingTeam) {
			return true
		}
	}
	// Check if any selected group is NOT in tracking groups
	if (formData.visibleGroups.length > 0) {
		const hasNonTrackingGroup = formData.visibleGroups.some(
			(groupId) => !trackingGroups.value.includes(groupId),
		)
		if (hasNonTrackingGroup) {
			return true
		}
	}
	return false
})

const adminSettingsUrl = computed(() => {
	return generateUrl('/settings/admin/attendance')
})

const calendarSyncSettingsUrl = computed(() => {
	return generateUrl('/settings/admin/attendance#calendar-sync')
})

const hasCalendarReference = computed(() => {
	return (
		calendarReference.value.calendarUri
        && calendarReference.value.calendarEventUid
	)
})

const getTypeLabel = (type, isGuest = false) => {
	switch (type) {
	case 'user':
		return isGuest ? t('attendance', 'Guest account') : t('attendance', 'User')
	case 'group':
		return t('attendance', 'Group')
	case 'team':
		return t('attendance', 'Team')
	default:
		return ''
	}
}

// Convert string datetime to Date object for NcDateTimePickerNative
const startDateObject = computed(() => {
	if (!formData.startDatetime) return null
	const date = new Date(formData.startDatetime)
	return isNaN(date.getTime()) ? null : date
})

const endDateObject = computed(() => {
	if (!formData.endDatetime) return null
	const date = new Date(formData.endDatetime)
	return isNaN(date.getTime()) ? null : date
})

const deadlineAbsoluteDateObject = computed(() => {
	if (!deadlineAbsolute.value) return null
	const date = new Date(deadlineAbsolute.value)
	return isNaN(date.getTime()) ? null : date
})

/**
 * Resolve the deadline (as a Date) for a given occurrence start.
 * Returns null when no deadline applies.
 *
 * @param {string} occurrenceStartLocal Local-input datetime ("YYYY-MM-DDTHH:mm").
 * @return {Date|null}
 */
const resolveDeadlineFor = (occurrenceStartLocal) => {
	if (deadlineMode.value === 'none') return null
	if (deadlineMode.value === 'relative') {
		if (!occurrenceStartLocal) return null
		const start = new Date(occurrenceStartLocal)
		if (isNaN(start.getTime())) return null
		return new Date(start.getTime() - deadlineRelativeOffsetMs.value)
	}
	// absolute
	if (!deadlineAbsolute.value) return null
	const abs = new Date(deadlineAbsolute.value)
	if (isNaN(abs.getTime())) return null
	if (!isRecurring.value || deadlineAbsoluteLiteral.value) {
		return abs
	}
	// Recurring + non-literal: shift by the delta between this occurrence and
	// the reference start. Same offset semantics as the legacy implementation.
	if (!formData.startDatetime || !occurrenceStartLocal) return abs
	const refStart = new Date(formData.startDatetime).getTime()
	const occStart = new Date(occurrenceStartLocal).getTime()
	if (isNaN(refStart) || isNaN(occStart)) return abs
	return new Date(abs.getTime() + (occStart - refStart))
}

const deadlineWarning = computed(() => {
	if (deadlineMode.value === 'none') return null
	if (deadlineMode.value === 'relative' && deadlineRelativeValue.value < 1) {
		return t('attendance', 'Time before start must be at least 1 minute')
	}
	if (deadlineMode.value === 'absolute' && !deadlineAbsolute.value) {
		return null
	}
	const deadline = resolveDeadlineFor(formData.startDatetime)
	if (!deadline) return null
	const start = formData.startDatetime ? new Date(formData.startDatetime) : null
	if (deadline.getTime() <= Date.now()) {
		return t('attendance', 'Response deadline must be in the future')
	}
	if (start && !isNaN(start.getTime()) && deadline >= start) {
		return t('attendance', 'Response deadline must be before the appointment starts')
	}
	return null
})

const onDeadlineAbsoluteChange = (value) => {
	if (!value) {
		deadlineAbsolute.value = ''
		return
	}
	const date = value instanceof Date ? value : new Date(value)
	if (isNaN(date.getTime())) return
	deadlineAbsolute.value = formatDateTimeForInput(date.toISOString())
}

// Watch for changes to visibilityItems to update formData
watch(visibilityItems, (selected) => {
	const selectedArray = Array.isArray(selected)
		? selected
		: selected
			? [selected]
			: []
	formData.visibleUsers = selectedArray
		.filter((item) => item && item.type === 'user')
		.map((item) => item.value)
	formData.visibleGroups = selectedArray
		.filter((item) => item && item.type === 'group')
		.map((item) => item.value)
	formData.visibleTeams = selectedArray
		.filter((item) => item && item.type === 'team')
		.map((item) => item.value)
})

const formatDateTimeForInput = (dateTime) => {
	if (!dateTime) return ''
	const date = new Date(dateTime)
	if (isNaN(date.getTime())) return ''

	const year = date.getFullYear()
	const month = String(date.getMonth() + 1).padStart(2, '0')
	const day = String(date.getDate()).padStart(2, '0')
	const hours = String(date.getHours()).padStart(2, '0')
	const minutes = String(date.getMinutes()).padStart(2, '0')

	return `${year}-${month}-${day}T${hours}:${minutes}`
}

const loadAppointment = async () => {
	if (!props.appointmentId) return

	loading.value = true
	try {
		const response = await axios.get(
			generateUrl(
				`/apps/attendance/api/appointments/${props.appointmentId}`,
			),
		)
		const appointment = response.data

		formData.name = props.mode === 'copy'
			? `${appointment.name} (${t('attendance', 'Copy')})`
			: appointment.name
		formData.description = appointment.description || ''

		// For copy mode, leave dates empty
		if (props.mode === 'copy') {
			formData.startDatetime = ''
			formData.endDatetime = ''
		} else {
			formData.startDatetime = formatDateTimeForInput(
				appointment.startDatetime,
			)
			formData.endDatetime = formatDateTimeForInput(
				appointment.endDatetime,
			)
		}

		// In edit mode we always default to the absolute picker — the stored
		// deadline is a single absolute moment and decomposing it back into
		// "X minutes/hours/..." can produce awkward numbers (e.g. "47
		// minutes"). The user can switch to relative mode explicitly if they
		// want to re-anchor it to the start. Copy mode drops the deadline
		// entirely; the new dates may be far away from the source deadline.
		hadDeadlineInitially.value = Boolean(
			props.mode === 'edit' && appointment.responseDeadline,
		)
		if (hadDeadlineInitially.value) {
			deadlineMode.value = 'absolute'
			deadlineAbsolute.value = formatDateTimeForInput(
				appointment.responseDeadline,
			)
		} else {
			deadlineMode.value = 'none'
			deadlineAbsolute.value = ''
		}

		// Load visibility settings (enriched data with id, label, type)
		const users = appointment.visibleUsers || []
		const groups = appointment.visibleGroups || []
		const teams = appointment.visibleTeams || []

		// Store raw IDs for form submission
		formData.visibleUsers = users.map((u) => u.id)
		formData.visibleGroups = groups.map((g) => g.id)
		formData.visibleTeams = teams.map((t) => t.id)

		// Convert enriched data to visibility items for NcSelect
		const items = []
		for (const user of users) {
			items.push({
				id: `user:${user.id}`,
				value: user.id,
				label: user.label,
				type: 'user',
			})
		}
		for (const group of groups) {
			items.push({
				id: `group:${group.id}`,
				value: group.id,
				label: group.label,
				type: 'group',
			})
		}
		for (const team of teams) {
			items.push({
				id: `team:${team.id}`,
				value: team.id,
				label: team.label,
				type: 'team',
			})
		}
		searchResults.value = [...items]
		visibilityItems.value = [...items]

		// Load notification preference for copy mode
		if (props.mode === 'copy') {
			sendNotification.value = appointment.sendNotification
		}

		// Load attachments
		attachments.value = (appointment.attachments || []).map((a) => ({
			fileId: a.fileId,
			fileName: a.fileName,
			filePath: a.filePath,
		}))

		// Load calendar reference (for edit mode, not copy)
		if (
			props.mode === 'edit'
            && appointment.calendarUri
            && appointment.calendarEventUid
		) {
			calendarReference.value = {
				calendarUri: appointment.calendarUri,
				calendarEventUid: appointment.calendarEventUid,
			}
		}

		// Load series info (for edit mode, not copy)
		if (props.mode === 'edit' && appointment.seriesId) {
			seriesId.value = appointment.seriesId
			seriesCount.value = appointment.seriesCount || 0
		}
	} catch (error) {
		console.error('Failed to load appointment:', error)
		showError(t('attendance', 'Error loading appointment'))
	} finally {
		loading.value = false
	}
}

const loadTrackingGroups = async () => {
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/admin/settings'),
		)
		if (response.data.whitelistedGroups) {
			trackingGroups.value = response.data.whitelistedGroups
		}
		if (response.data.whitelistedTeams) {
			// Extract team IDs from team objects
			trackingTeams.value = response.data.whitelistedTeams.map(
				(t) => t.id,
			)
		}
	} catch (error) {
		console.debug('Could not load tracking groups:', error)
	}
}

const onStartDatetimeChange = (newValue) => {
	// newValue is a Date object from NcDateTimePickerNative
	formData.startDatetime = newValue
		? formatDateTimeForInput(newValue.toISOString())
		: ''
}

const onStartDatetimeBlur = () => {
	// Auto-fill end datetime if not set (only on blur)
	if (formData.startDatetime && !formData.endDatetime) {
		const startDate = new Date(formData.startDatetime)
		if (!isNaN(startDate.getTime())) {
			const endDate = new Date(
				startDate.getTime() + 2.5 * 60 * 60 * 1000,
			)
			formData.endDatetime = formatDateTimeForInput(
				endDate.toISOString(),
			)
		}
	}
}

const onEndDatetimeChange = (newValue) => {
	// newValue is a Date object from NcDateTimePickerNative
	formData.endDatetime = newValue
		? formatDateTimeForInput(newValue.toISOString())
		: ''
}

const goBack = () => {
	emit('cancelled')
}

const onSearch = async (query) => {
	if (!query || query.length < 1) {
		searchResults.value = [...visibilityItems.value]
		return
	}

	isSearching.value = true
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/search/users-groups-teams'),
			{ params: { search: query } },
		)

		const newResults = response.data.map((item) => ({
			id: `${item.type}:${item.id}`,
			value: item.id,
			label: item.label,
			type: item.type,
			isGuest: !!item.isGuest,
		}))

		const mergedResults = [...visibilityItems.value]
		for (const result of newResults) {
			const isAlreadySelected = visibilityItems.value.some(
				(item) => item.id === result.id,
			)
			if (!isAlreadySelected) {
				mergedResults.push(result)
			}
		}

		// Offer to provision a guest account when the query is an email and
		// no existing user matches it. Gated on `guestInvitation` capability.
		const trimmedQuery = query.trim()
		if (guestInvitationAvailable.value && isEmailAddress(trimmedQuery)) {
			const exactUserMatch = newResults.some(
				(r) => r.type === 'user' && (r.value === trimmedQuery || r.label === trimmedQuery),
			)
			if (!exactUserMatch) {
				mergedResults.push({
					id: `create-guest:${trimmedQuery.toLowerCase()}`,
					value: trimmedQuery,
					label: t('attendance', 'Create guest account for {email}', { email: trimmedQuery }),
					type: 'create-guest',
					email: trimmedQuery,
					isGuest: false,
				})
			}
		}

		searchResults.value = mergedResults
	} catch (error) {
		console.error('Failed to search:', error)
		searchResults.value = [...visibilityItems.value]
	} finally {
		isSearching.value = false
	}
}

const removePlaceholder = (placeholder) => {
	visibilityItems.value = visibilityItems.value.filter((i) => i.id !== placeholder.id)
}

const addUserItem = (userItem) => {
	const alreadySelected = visibilityItems.value.some((i) => i.id === userItem.id)
	if (!alreadySelected) {
		// Reassign instead of .push() — the visibilityItems watcher fires only
		// on .value reassignment, not in-place array mutation.
		visibilityItems.value = [...visibilityItems.value, userItem]
	}
}

const guestCreatedHandlers = new Set()

const provisionGuestViaDialog = (email) => {
	// The Guests app exposes `OCA.Guests.openGuestDialog(app, shareWith)`. The
	// promise it returns never resolves for non-files/talk integrations, so we
	// listen for the `guests:user:created` bus event instead.
	if (!window.OCA?.Guests?.openGuestDialog) {
		return false
	}
	const handler = ({ username, name }) => {
		unsubscribeFromEvent('guests:user:created', handler)
		guestCreatedHandlers.delete(handler)
		const uid = username || email
		addUserItem({
			id: `user:${uid}`,
			value: uid,
			label: name || uid,
			type: 'user',
			isGuest: true,
		})
		showSuccess(t('attendance', 'Guest account created for {email}', { email }))
	}
	subscribeToEvent('guests:user:created', handler)
	guestCreatedHandlers.add(handler)
	try {
		window.OCA.Guests.openGuestDialog('attendance', email)
	} catch (error) {
		console.error('Failed to open Guests app dialog:', error)
		unsubscribeFromEvent('guests:user:created', handler)
		guestCreatedHandlers.delete(handler)
		return false
	}
	return true
}

const provisionGuestAccount = async (placeholder) => {
	const email = (placeholder.email || '').trim()
	if (!email) {
		return
	}
	removePlaceholder(placeholder)
	if (provisionGuestViaDialog(email)) {
		return
	}
	try {
		const response = await axios.post(
			generateUrl('/apps/attendance/api/guests'),
			{ email },
		)
		addUserItem({
			id: `user:${response.data.userId}`,
			value: response.data.userId,
			label: response.data.displayName || response.data.email,
			type: 'user',
			isGuest: !!response.data.isGuest,
		})
		showSuccess(
			response.data.alreadyExisted
				? t('attendance', 'Added existing guest {email}', { email })
				: t('attendance', 'Guest account created for {email}', { email }),
		)
	} catch (error) {
		console.error('Failed to create guest account:', error)
		const serverMessage = error?.response?.data?.error
		showError(serverMessage || t('attendance', 'Failed to create guest account'))
	}
}

watch(
	visibilityItems,
	(items) => {
		const placeholders = items.filter((i) => i.type === 'create-guest')
		for (const placeholder of placeholders) {
			provisionGuestAccount(placeholder)
		}
	},
	{ deep: true },
)

const openFilePicker = async () => {
	try {
		const picker = getFilePickerBuilder(
			t('attendance', 'Choose files or folders'),
		)
			.setMultiSelect(true)
			.allowDirectories(true)
			.addButton({
				label: t('attendance', 'Attach'),
				callback: async (nodes) => {
					for (const node of nodes) {
						await addAttachment(node)
					}
				},
			})
			.build()
		await picker.pick()
	} catch (error) {
		console.debug('File picker cancelled:', error)
	}
}

const addAttachment = (node) => {
	if (attachments.value.some((a) => a.fileId === node.fileid)) {
		return
	}
	attachments.value.push({
		fileId: node.fileid,
		fileName: node.basename,
		filePath: node.path,
	})
}

const removeAttachment = (fileId) => {
	attachments.value = attachments.value.filter((a) => a.fileId !== fileId)
}

const attachmentFileIds = computed(() =>
	attachments.value.map((a) => a.fileId),
)

const toServerTimezone = (datetime) => {
	if (!datetime) return datetime
	const date = new Date(datetime)
	return date.toISOString()
}

const handleCalendarEventSelect = (eventData) => {
	formData.name = eventData.name
	formData.description = eventData.description
	formData.startDatetime = formatDateTimeForInput(eventData.startDatetime)
	formData.endDatetime = formatDateTimeForInput(eventData.endDatetime)
	calendarReference.value = {
		calendarUri: eventData.calendarUri,
		calendarEventUid: eventData.calendarEventUid,
	}
}

const bulkImporting = ref(false)

const handleBulkImport = async (eventDataList) => {
	bulkImporting.value = true
	saving.value = true

	try {
		const appointments = eventDataList.map((eventData) => {
			const item = {
				name: eventData.name,
				description: eventData.description,
				startDatetime: toServerTimezone(eventData.startDatetime),
				endDatetime: toServerTimezone(eventData.endDatetime),
				calendarUri: eventData.calendarUri,
				calendarEventUid: eventData.calendarEventUid,
			}
			const deadline = resolveDeadlineFor(
				formatDateTimeForInput(eventData.startDatetime),
			)
			if (deadline) {
				item.responseDeadline = deadline.toISOString()
			}
			return item
		})

		const response = await axios.post(
			generateUrl('/apps/attendance/api/appointments/bulk'),
			{ appointments },
		)

		const created = response.data?.created || []
		const errors = response.data?.errors || []

		if (created.length > 0) {
			showSuccess(
				n(
					'attendance',
					'{count} appointment created',
					'{count} appointments created',
					created.length,
					{ count: created.length },
				),
			)
		}
		if (errors.length > 0) {
			showError(
				n(
					'attendance',
					'{count} appointment failed to import',
					'{count} appointments failed to import',
					errors.length,
					{ count: errors.length },
				),
			)
		}

		emit('saved')
	} catch (error) {
		console.error('Bulk import failed:', error)
		showError(t('attendance', 'Error importing appointments'))
	} finally {
		bulkImporting.value = false
		saving.value = false
	}
}

const handleRecurringCreate = async () => {
	saving.value = true

	try {
		const duration = appointmentDuration.value
		const appointments = recurrenceOccurrences.value.map(
			(occurrenceDate) => {
				const startDt = occurrenceDate.toISOString()
				const endDt = new Date(
					occurrenceDate.getTime() + duration,
				).toISOString()
				const item = {
					name: formData.name,
					description: formData.description,
					startDatetime: startDt,
					endDatetime: endDt,
					visibleUsers: formData.visibleUsers || [],
					visibleGroups: formData.visibleGroups || [],
					visibleTeams: formData.visibleTeams || [],
				}
				const occurrenceDeadline = resolveDeadlineFor(
					formatDateTimeForInput(startDt),
				)
				if (occurrenceDeadline) {
					item.responseDeadline = occurrenceDeadline.toISOString()
				}
				return item
			},
		)

		const response = await axios.post(
			generateUrl('/apps/attendance/api/appointments/bulk'),
			{
				appointments,
				sendNotification: sendNotification.value,
				attachments: attachmentFileIds.value,
			},
		)

		const created = response.data?.created || []
		const errors = response.data?.errors || []

		if (created.length > 0) {
			showSuccess(
				n(
					'attendance',
					'{count} appointment created',
					'{count} appointments created',
					created.length,
					{ count: created.length },
				),
			)
		}
		if (errors.length > 0) {
			showError(
				n(
					'attendance',
					'{count} appointment failed to create',
					'{count} appointments failed to create',
					errors.length,
					{ count: errors.length },
				),
			)
		}

		emit('saved')
	} catch (error) {
		console.error('Failed to create recurring appointments:', error)
		showError(t('attendance', 'Error creating appointments'))
	} finally {
		saving.value = false
	}
}

const handleSubmit = async () => {
	if (saving.value) return

	// Manual validation for datetime fields
	if (!formData.name?.trim()) {
		showError(t('attendance', 'Please enter an appointment name'))
		return
	}
	if (!formData.startDatetime) {
		showError(t('attendance', 'Please select a start date and time'))
		return
	}
	if (!formData.endDatetime) {
		showError(t('attendance', 'Please select an end date and time'))
		return
	}
	if (new Date(formData.endDatetime) <= new Date(formData.startDatetime)) {
		showError(t('attendance', 'End date must be after start date'))
		return
	}
	if (recurrenceWarning.value) {
		showError(recurrenceWarning.value)
		return
	}
	if (deadlineWarning.value) {
		showError(deadlineWarning.value)
		return
	}

	// Recurring creation uses bulk endpoint
	if (isRecurring.value) {
		return handleRecurringCreate()
	}

	// If editing a series appointment, show the series dialog
	if (props.mode === 'edit' && seriesId.value) {
		showSeriesDialog.value = true
		return
	}

	await saveAppointment()
}

const handleSeriesEditConfirm = async (scope) => {
	showSeriesDialog.value = false
	await saveAppointment(scope)
}

const saveAppointment = async (scope = 'single') => {
	saving.value = true

	try {
		const startDatetimeWithTz = toServerTimezone(formData.startDatetime)
		const endDatetimeWithTz = toServerTimezone(formData.endDatetime)
		// Resolve the deadline against the form's reference start. For series
		// updates the server rebases the result onto each sibling, so this
		// reference value is what we always send.
		const deadlineDate = resolveDeadlineFor(formData.startDatetime)
		const deadlineWithTz = deadlineDate
			? toServerTimezone(formatDateTimeForInput(deadlineDate.toISOString()))
			: ''

		let appointmentId = props.appointmentId

		if (props.mode === 'edit') {
			// Send responseDeadline only when it would change something:
			//   - resolved value present → set/replace.
			//   - resolved empty + had one before → '' clears it server-side.
			//   - resolved empty + never had one  → omit entirely.
			const updatePayload = {
				name: formData.name,
				description: formData.description,
				startDatetime: startDatetimeWithTz,
				endDatetime: endDatetimeWithTz,
				visibleUsers: formData.visibleUsers || [],
				visibleGroups: formData.visibleGroups || [],
				visibleTeams: formData.visibleTeams || [],
				attachments: attachmentFileIds.value,
				scope,
			}
			if (deadlineWithTz) {
				updatePayload.responseDeadline = deadlineWithTz
			} else if (hadDeadlineInitially.value) {
				updatePayload.responseDeadline = ''
			}
			await axios.put(
				generateUrl(
					`/apps/attendance/api/appointments/${props.appointmentId}`,
				),
				updatePayload,
			)
			showSuccess(t('attendance', 'Appointment updated'))
		} else {
			// Create new appointment (or copy)
			const createPayload = {
				name: formData.name,
				description: formData.description,
				startDatetime: startDatetimeWithTz,
				endDatetime: endDatetimeWithTz,
				visibleUsers: formData.visibleUsers || [],
				visibleGroups: formData.visibleGroups || [],
				visibleTeams: formData.visibleTeams || [],
				sendNotification: sendNotification.value,
				calendarUri: calendarReference.value.calendarUri,
				calendarEventUid: calendarReference.value.calendarEventUid,
				attachments: attachmentFileIds.value,
			}
			if (deadlineWithTz) {
				createPayload.responseDeadline = deadlineWithTz
			}
			const response = await axios.post(
				generateUrl('/apps/attendance/api/appointments'),
				createPayload,
			)
			appointmentId = response.data?.id
			showSuccess(t('attendance', 'Appointment created'))
		}

		emit('saved', appointmentId)
	} catch (error) {
		console.error('Failed to save appointment:', error)
		showError(
			props.mode === 'edit'
				? t('attendance', 'Error updating appointment')
				: t('attendance', 'Error creating appointment'),
		)
	} finally {
		saving.value = false
	}
}

onMounted(async () => {
	await Promise.all([loadTrackingGroups(), loadPermissions()])
	if (props.mode === 'edit' || props.mode === 'copy') {
		await loadAppointment()
	}
})

onBeforeUnmount(() => {
	// Cancel any guest-creation listeners still waiting for a Guests-app
	// dialog that the user closed without submitting — otherwise a later
	// unrelated `guests:user:created` event would fire stale handlers.
	for (const handler of guestCreatedHandlers) {
		unsubscribeFromEvent('guests:user:created', handler)
	}
	guestCreatedHandlers.clear()
})
</script>

<style scoped lang="scss">
.appointment-form-view {
    padding: 20px;
    max-width: 800px;
    margin: 0 auto;
}

.form-header {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 24px;

    .header-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    h2 {
        margin: 0;
    }
}

.loading-state {
    text-align: center;
    padding: 40px;
    color: var(--color-text-lighter);
}

.appointment-form {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.form-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;

    h3 {
        margin: 0 0 4px 0;
        font-size: 16px;
        font-weight: 600;
    }

    .hint-text {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
        margin: 0 0 8px 0;
    }

    .visibility-warning {
        margin-top: 12px;

        a {
            text-decoration: underline;
            color: inherit;
        }
    }

    .guest-invite-hint {
        display: flex;
        align-items: center;
        gap: 6px;
        margin: 8px 0 0 0;
        font-size: 12px;
        color: var(--color-text-maxcontrast);
    }
}

.datetime-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;

    @media (max-width: 600px) {
        grid-template-columns: 1fr;
    }
}

.deadline-field {
    display: flex;
    align-items: end;
    gap: 12px;
    flex-wrap: wrap;

    > :first-child {
        flex: 1;
        min-width: 200px;
    }
}

.deadline-mode-row {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 8px;
}

.deadline-relative-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.deadline-relative-value {
    flex: 0 0 110px;
    max-width: 110px;
}

/* Match the native <select>'s outer height (border-box, 1px border top
 * and bottom = clickable-area + 2px) with the NcTextField wrapper so the
 * two controls share a baseline. */
.deadline-relative-value :deep(.input-field__main-wrapper) {
    min-height: calc(var(--default-clickable-area, 34px) + 2px);
}

.deadline-relative-unit {
    flex: 0 0 150px;
    max-width: 150px;
    height: calc(var(--default-clickable-area, 34px) + 2px);
    padding: 0 32px 0 12px;
    border: 1px solid var(--color-border-dark, #ccc);
    border-radius: var(--border-radius-element, var(--border-radius, 4px));
    background-color: var(--color-main-background, #fff);
    color: var(--color-main-text, #000);
    font: inherit;
    /* Replace the default arrow with one positioned by us so the trigger
     * matches the rest of the form's controls. */
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
}

.deadline-relative-unit:focus {
    outline: 2px solid var(--color-primary-element, var(--color-primary));
    outline-offset: 1px;
}

.deadline-relative-suffix {
    color: var(--color-text-maxcontrast);
}

.deadline-warning {
    color: var(--color-warning);
    margin-top: 4px;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 5px;

    label {
        font-weight: 600;
        font-size: 14px;
        color: var(--color-main-text);
    }
}

.attachment-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    min-height: 32px;
    padding: 4px 0;
    margin-bottom: 8px;

    .no-attachments {
        color: var(--color-text-maxcontrast);
        font-size: 13px;
    }
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

.series-info-header {
    display: flex;
    align-items: center;
    gap: 8px;

    h3 {
        margin: 0;
    }
}

.calendar-link-header {
    display: flex;
    align-items: center;
    gap: 8px;

    h3 {
        margin: 0;
    }

    .auto-sync-chip-link {
        text-decoration: none;
    }
}
</style>
