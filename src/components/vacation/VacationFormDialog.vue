<template>
	<NcDialog :name="vacation ? t('attendance', 'Edit vacation') : t('attendance', 'Add vacation')"
		isForm
		data-test="vacation-form-dialog"
		@submit="submit"
		@closing="emit('close')">
		<div class="vacation-form">
			<div class="vacation-form__dates">
				<div class="vacation-form__field">
					<label for="vacation-start-date">{{ t('attendance', 'From') }}</label>
					<input id="vacation-start-date"
						v-model="startDate"
						type="date"
						required
						data-test="input-vacation-start"
						@change="keepEndAfterStart">
				</div>
				<div class="vacation-form__field">
					<label for="vacation-end-date">{{ t('attendance', 'To') }}</label>
					<input id="vacation-end-date"
						v-model="endDate"
						type="date"
						required
						:min="startDate"
						data-test="input-vacation-end">
				</div>
			</div>
			<NcTextArea v-model="note"
				:label="t('attendance', 'Note (optional)')"
				:helperText="t('attendance', 'Your name is shown as a hint when appointments are created, so organizers see who is on vacation — without your note. Period and note are only visible to members with access to the vacation calendar.')"
				:maxlength="NOTE_MAX_LENGTH"
				rows="2"
				data-test="input-vacation-note" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="emit('close')">
				{{ t('attendance', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary"
				type="submit"
				:disabled="saving"
				data-test="button-save-vacation">
				{{ t('attendance', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'
import { ref } from 'vue'
import { todayDateOnly } from '../../utils/dateOnly.js'

const props = defineProps({
	// The entry being edited; null adds a new one.
	vacation: {
		type: Object,
		default: null,
	},
})

const emit = defineEmits(['saved', 'close'])

// Same bound as VacationService::NOTE_MAX_LENGTH, which cuts silently.
const NOTE_MAX_LENGTH = 500

const startDate = ref(props.vacation?.startDate ?? todayDateOnly())
const endDate = ref(props.vacation?.endDate ?? startDate.value)
const note = ref(props.vacation?.note ?? '')
const saving = ref(false)

function keepEndAfterStart() {
	if (startDate.value && endDate.value < startDate.value) {
		endDate.value = startDate.value
	}
}

async function submit() {
	if (saving.value) return

	saving.value = true
	const payload = {
		startDate: startDate.value,
		endDate: endDate.value,
		note: note.value.trim() || null,
	}
	try {
		const response = props.vacation
			? await axios.put(generateUrl(`/apps/attendance/api/vacations/${props.vacation.id}`), payload)
			: await axios.post(generateUrl('/apps/attendance/api/vacations'), payload)
		emit('saved', response.data)
	} catch (error) {
		console.error('Failed to save vacation:', error)
		showError(t('attendance', 'Something went wrong'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.vacation-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 8px;
}

.vacation-form__dates {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.vacation-form__field {
	display: flex;
	flex: 1 1 160px;
	flex-direction: column;
	gap: 4px;
}

.vacation-form__field label {
	font-weight: bold;
}

.vacation-form__field input {
	width: 100%;
	margin: 0;
}
</style>
