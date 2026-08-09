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
					<div class="person-detail__answer">
						<ResponseDot :response="entry.response" />
						<strong>{{ entry.name }}</strong>
						<span class="person-detail__date">{{ formatDate(entry.startDatetime) }}</span>
					</div>
					<div v-if="entry.checkinState" class="person-detail__checkin">
						<span>{{ t("attendance", "Checked in?") }}</span>
						<ResponseDot :response="entry.checkinState" :label="checkinLabel(entry.checkinState)" />
					</div>
				</div>
				<div v-if="isReversal(entry)" class="person-detail__note">
					{{ t("attendance", "Said no but attended") }}
				</div>
				<div v-if="entry.comment && entry.comment.trim()" class="person-detail__comment">
					<CommentIcon :size="13" />
					<span>{{ entry.comment }}</span>
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
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import ResponseDot from '../appointment/ResponseDot.vue'
import LoadingState from '../common/LoadingState.vue'
import { formatDate } from '../../utils/datetime.js'

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
	() => `${props.person.userId}:${JSON.stringify(props.query)}`,
	() => load(),
	{ immediate: true },
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
 * @param {string} value - Stored check-in state.
 * @return {string} Human-readable attendance.
 */
function checkinLabel(value) {
	return value === 'yes' ? t('attendance', 'Present') : t('attendance', 'Absent')
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
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: space-between;
}

.person-detail__answer {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.person-detail__answer strong {
    font-size: 14px;
}

.person-detail__date {
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.person-detail__checkin {
    align-items: center;
    color: var(--color-text-maxcontrast);
    display: flex;
    font-size: 13px;
    gap: 5px;
}

.person-detail__note,
.person-detail__comment {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    padding-top: 5px;
}

.person-detail__comment {
    align-items: flex-start;
    display: flex;
    font-style: italic;
    gap: 8px;
}
</style>
