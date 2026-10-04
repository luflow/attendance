<template>
	<div class="team-vacations">
		<LoadingState v-if="loading" :size="24" />
		<NcNoteCard v-else-if="failed" type="error">
			{{ t('attendance', 'Error loading data') }}
		</NcNoteCard>
		<p v-else-if="months.length === 0" class="team-vacations__empty">
			{{ t('attendance', 'Nobody has recorded a vacation for the next months.') }}
		</p>
		<template v-else>
			<section v-for="month in months"
				:key="month.key"
				class="team-vacations__month"
				data-test="team-vacation-month">
				<h3 class="team-vacations__month-name">
					{{ month.label }}
				</h3>
				<ul class="team-vacations__list">
					<li v-for="(entry, index) in month.entries"
						:key="`${entry.userId}-${entry.startDate}-${entry.endDate}-${index}`"
						class="team-vacations__item"
						data-test="team-vacation-entry">
						<NcAvatar :user="entry.userId"
							:displayName="entry.displayName"
							:size="32"
							hideStatus />
						<div class="team-vacations__text">
							<span class="team-vacations__name">{{ entry.displayName }}</span>
							<span class="team-vacations__details">{{ entryDetails(entry) }}</span>
						</div>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcAvatar, NcNoteCard } from '@nextcloud/vue'
import { computed, onMounted, ref } from 'vue'
import LoadingState from '../common/LoadingState.vue'
import { formatDateOnlyRange, formatMonthOfDateOnly } from '../../utils/dateOnly.js'

const loading = ref(true)
const failed = ref(false)
const entries = ref([])

// The server orders entries by start date, so the months come out in order.
const months = computed(() => {
	const groups = new Map()
	for (const entry of entries.value) {
		const key = entry.startDate.slice(0, 7)
		if (!groups.has(key)) {
			groups.set(key, { key, label: formatMonthOfDateOnly(entry.startDate), entries: [] })
		}
		groups.get(key).entries.push(entry)
	}
	return [...groups.values()]
})

function entryDetails(entry) {
	const range = formatDateOnlyRange(entry.startDate, entry.endDate, { month: 'short', day: 'numeric' })
	return entry.note ? `${range} · ${entry.note}` : range
}

async function reload() {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/vacations/team'))
		entries.value = response.data
		failed.value = false
	} catch (error) {
		console.error('Failed to load team vacations:', error)
		failed.value = true
	} finally {
		loading.value = false
	}
}

defineExpose({ reload })

onMounted(reload)
</script>

<style scoped>
.team-vacations__month + .team-vacations__month {
	margin-top: 16px;
}

.team-vacations__month-name {
	margin: 0 0 4px;
	font-size: 1em;
	font-weight: bold;
}

.team-vacations__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 600px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.team-vacations__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 6px 0;
}

.team-vacations__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.team-vacations__details {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}
</style>
