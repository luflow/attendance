<template>
	<div class="my-vacations">
		<LoadingState v-if="loading" :size="24" />
		<NcNoteCard v-else-if="failed" type="error">
			{{ t('attendance', 'Error loading data') }}
		</NcNoteCard>
		<template v-else>
			<ul v-if="vacations.length > 0" class="my-vacations__list">
				<li v-for="vacation in vacations"
					:key="vacation.id"
					class="my-vacations__item"
					data-test="vacation-item">
					<div class="my-vacations__text">
						<span class="my-vacations__dates">{{ formatDateOnlyRange(vacation.startDate, vacation.endDate) }}</span>
						<span v-if="vacation.note" class="my-vacations__note">{{ vacation.note }}</span>
					</div>
					<NcButton variant="tertiary"
						:aria-label="t('attendance', 'Edit vacation')"
						data-test="button-edit-vacation"
						@click="openForm(vacation)">
						<template #icon>
							<Pencil :size="18" />
						</template>
					</NcButton>
					<NcButton variant="tertiary"
						:aria-label="t('attendance', 'Delete vacation')"
						data-test="button-delete-vacation"
						@click="vacationToDelete = vacation">
						<template #icon>
							<TrashCan :size="18" />
						</template>
					</NcButton>
				</li>
			</ul>
			<p v-else class="my-vacations__empty">
				{{ t('attendance', 'No vacations recorded yet.') }}
			</p>

			<NcButton variant="secondary"
				data-test="button-add-vacation"
				@click="openForm(null)">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('attendance', 'Add vacation') }}
			</NcButton>
		</template>

		<VacationFormDialog v-if="formOpen"
			:vacation="editedVacation"
			@saved="onSaved"
			@close="formOpen = false" />

		<NcDialog v-if="vacationToDelete"
			:name="t('attendance', 'Delete vacation')"
			data-test="vacation-delete-dialog"
			@closing="vacationToDelete = null">
			<p>
				<!-- TRANSLATORS: Confirmation before deleting one of the current user's own vacation entries in the personal settings, on the web and in the mobile app. -->
				{{ t('attendance', 'Do you want to delete this vacation entry?') }}
			</p>
			<template #actions>
				<NcButton variant="tertiary" @click="vacationToDelete = null">
					{{ t('attendance', 'Cancel') }}
				</NcButton>
				<NcButton variant="error"
					data-test="button-confirm-delete-vacation"
					@click="deleteVacation">
					{{ t('attendance', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-if="vacationToApply"
			:name="applyTitle"
			data-test="vacation-apply-dialog"
			@closing="vacationToApply = null">
			<p>{{ applyQuestion }}</p>
			<template #actions>
				<NcButton variant="tertiary"
					data-test="button-vacation-leave-open"
					@click="vacationToApply = null">
					{{ t('attendance', 'Leave open') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="applying"
					data-test="button-vacation-apply"
					@click="applyToAppointments">
					{{ applyConfirmLabel }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { onMounted, ref } from 'vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import LoadingState from '../common/LoadingState.vue'
import VacationFormDialog from './VacationFormDialog.vue'
import { formatDateOnlyRange } from '../../utils/dateOnly.js'

const emit = defineEmits(['changed'])

// TRANSLATORS: Question after saving a vacation, on the web and in the mobile app. The quoted "No" is the app's own no-response label — translate it to match that label's wording in this language.
const applyTitle = t('attendance', 'Set appointments to "No"?')
// TRANSLATORS: Confirm button of the question asked after saving a vacation. The quoted "No" is the app's own no-response label — translate it to match that label's wording in this language.
const applyConfirmLabel = t('attendance', 'Set to "No"')
// TRANSLATORS: Body of the question after saving a vacation; only appointments the person has not answered yet are touched. The quoted "No" is the app's own no-response label — translate it to match that label's wording in this language.
const applyQuestion = t('attendance', 'Should all upcoming appointments during your vacation that you have not answered yet be set to "No"?')

const loading = ref(true)
const failed = ref(false)
const vacations = ref([])
const formOpen = ref(false)
const editedVacation = ref(null)
const vacationToDelete = ref(null)
const vacationToApply = ref(null)
const applying = ref(false)

async function loadVacations() {
	try {
		const response = await axios.get(generateUrl('/apps/attendance/api/vacations'))
		vacations.value = response.data
	} catch (error) {
		console.error('Failed to load vacations:', error)
		failed.value = true
	} finally {
		loading.value = false
	}
}

function openForm(vacation) {
	editedVacation.value = vacation
	formOpen.value = true
}

function onSaved(saved) {
	const previous = editedVacation.value
	formOpen.value = false
	vacations.value = [...vacations.value.filter((vacation) => vacation.id !== saved.id), saved]
		.sort((a, b) => a.startDate.localeCompare(b.startDate) || a.id - b.id)
	emit('changed')

	// A note-only edit changes nothing about which appointments are affected.
	const sameDays = previous !== null
		&& previous.startDate === saved.startDate
		&& previous.endDate === saved.endDate
	if (!sameDays) {
		vacationToApply.value = saved
	}
}

async function deleteVacation() {
	const vacation = vacationToDelete.value
	if (!vacation) return

	try {
		await axios.delete(generateUrl(`/apps/attendance/api/vacations/${vacation.id}`))
		vacations.value = vacations.value.filter((entry) => entry.id !== vacation.id)
		emit('changed')
	} catch (error) {
		console.error('Failed to delete vacation:', error)
		showError(t('attendance', 'Something went wrong'))
	} finally {
		vacationToDelete.value = null
	}
}

async function applyToAppointments() {
	const vacation = vacationToApply.value
	if (!vacation || applying.value) return

	applying.value = true
	try {
		const response = await axios.post(generateUrl(`/apps/attendance/api/vacations/${vacation.id}/apply-responses`))
		const count = response.data.count ?? 0
		if (count > 0) {
			// TRANSLATORS: Confirmation after the question asked on saving a vacation; %n is how many appointments were answered. The quoted "No" is the app's own no-response label — translate it to match that label's wording in this language.
			showSuccess(n('attendance', '%n appointment set to "No"', '%n appointments set to "No"', count))
		} else {
			showInfo(t('attendance', 'No unanswered appointments during this vacation'))
		}
	} catch (error) {
		console.error('Failed to answer appointments during vacation:', error)
		showError(t('attendance', 'Something went wrong'))
	} finally {
		applying.value = false
		vacationToApply.value = null
	}
}

onMounted(loadVacations)
</script>

<style scoped>
.my-vacations__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 600px;
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}

.my-vacations__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
}

.my-vacations__text {
	display: flex;
	flex: 1;
	flex-direction: column;
	min-width: 0;
}

.my-vacations__dates {
	font-weight: bold;
}

.my-vacations__note {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.my-vacations__empty {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}
</style>
