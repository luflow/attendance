<template>
	<NcAppSidebar
		:name="person.displayName"
		:subname="subname"
		data-test="statistics-person-sidebar"
		@close="emit('close')">
		<LoadingState v-if="loading" :text="t('attendance', 'Loading …')" />

		<NcEmptyContent
			v-else-if="entries.length === 0"
			:name="t('attendance', 'No appointments in this period')">
			<template #icon>
				<CalendarBlankIcon :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="person-detail">
			<li v-for="entry in entries" :key="entry.appointmentId" class="person-detail__item">
				<div class="person-detail__head">
					<span class="person-detail__date">{{ formatDate(entry.startDatetime) }}</span>
					<span class="person-detail__name">{{ entry.name }}</span>
				</div>
				<div class="person-detail__states">
					<span class="person-detail__chip" :class="`person-detail__chip--${entry.response ?? 'none'}`">
						{{ responseLabel(entry.response) }}
					</span>
					<span
						v-if="entry.attendanceRecorded"
						class="person-detail__chip"
						:class="`person-detail__chip--checkin-${entry.checkinState ?? 'none'}`">
						{{ checkinLabel(entry.checkinState) }}
					</span>
					<span v-if="isReversal(entry)" class="person-detail__note">
						{{ t("attendance", "Said no but attended") }}
					</span>
				</div>
			</li>
		</ul>
	</NcAppSidebar>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppSidebar, NcEmptyContent } from '@nextcloud/vue'
import { computed, ref, watch } from 'vue'
import CalendarBlankIcon from 'vue-material-design-icons/CalendarBlank.vue'
import LoadingState from '../common/LoadingState.vue'

const props = defineProps({
	person: { type: Object, required: true },
	query: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const entries = ref([])
const loading = ref(false)

const subname = computed(() => n(
	'attendance',
	'%n appointment in this period',
	'%n appointments in this period',
	entries.value.length,
))

watch(
	() => [props.person.userId, props.query],
	() => load(),
	{ immediate: true, deep: true },
)

/**
 * Load the person's appointments for the filters currently in effect.
 */
async function load() {
	loading.value = true
	try {
		const response = await axios.get(
			generateUrl('/apps/attendance/api/statistics/person/{userId}', { userId: props.person.userId }),
			{ params: props.query },
		)
		entries.value = response.data.entries
	} catch (error) {
		console.error('Failed to load person statistics:', error)
		entries.value = []
	} finally {
		loading.value = false
	}
}

/**
 * @param {object} entry - Appointment entry.
 * @return {boolean} Whether the person turned up after declining.
 */
function isReversal(entry) {
	return entry.response === 'no' && entry.checkinState === 'yes'
}

/**
 * @param {?string} value - Stored response.
 * @return {string} Human-readable answer.
 */
function responseLabel(value) {
	if (value === 'yes') return t('attendance', 'Yes')
	if (value === 'no') return t('attendance', 'No')
	if (value === 'maybe') return t('attendance', 'Maybe')
	return t('attendance', 'No response')
}

/**
 * @param {?string} value - Stored check-in state.
 * @return {string} Human-readable attendance.
 */
function checkinLabel(value) {
	if (value === 'yes') return t('attendance', 'Present')
	if (value === 'no') return t('attendance', 'Absent')
	return t('attendance', 'Not recorded')
}

/**
 * @param {?string} value - ISO timestamp.
 * @return {string} Local date.
 */
function formatDate(value) {
	return value ? new Date(value).toLocaleDateString() : ''
}
</script>

<style scoped>
.person-detail {
    padding: 0 12px;
}

.person-detail__item {
    border-bottom: 1px solid var(--color-border);
    padding: 8px 0;
}

.person-detail__head {
    display: flex;
    gap: 8px;
}

.person-detail__date {
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.person-detail__name {
    font-weight: bold;
}

.person-detail__states {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 4px;
}

.person-detail__chip {
    border-radius: var(--border-radius-pill);
    background-color: var(--color-background-dark);
    font-size: 0.85rem;
    padding: 1px 8px;
}

.person-detail__chip--yes,
.person-detail__chip--checkin-yes {
    background-color: var(--color-success);
    color: var(--color-primary-element-text);
}

.person-detail__chip--no,
.person-detail__chip--checkin-no {
    background-color: var(--color-error);
    color: var(--color-primary-element-text);
}

.person-detail__chip--maybe {
    background-color: var(--color-warning);
}

.person-detail__note {
    color: var(--color-text-maxcontrast);
    font-size: 0.85rem;
}
</style>
