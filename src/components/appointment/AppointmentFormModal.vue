<template>
	<NcModal v-if="show" @close="handleClose">
		<div class="modal-content">
			<h2>{{ isEdit ? t('attendance', 'Edit Appointment') : t('attendance', 'Create Appointment') }}</h2>
			<form @submit.prevent="handleSubmit">
				<NcTextField 
					v-model="formData.name" 
					:label="t('attendance', 'Appointment Name')"
					required />
				
				<NcTextArea 
					v-model="formData.description" 
					:label="t('attendance', 'Description')"
					:placeholder="t('attendance', 'You can use **bold** and *italic* formatting')"
					:rows="6" />
				
				<div class="form-field">
					<label for="start-datetime">{{ t('attendance', 'Start Date & Time') }}</label>
					<input 
						id="start-datetime"
						v-model="formData.startDatetime"
						type="datetime-local" 
						required 
						@blur="handleStartDateBlur" />
				</div>
				
				<div class="form-field">
					<label for="end-datetime">{{ t('attendance', 'End Date & Time') }}</label>
					<input 
						id="end-datetime"
						ref="endDatetimePicker"
						v-model="formData.endDatetime"
						type="datetime-local" 
						required />
				</div>
				
				<div class="form-actions">
					<NcButton type="secondary" @click="handleClose">
						{{ t('attendance', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" native-type="submit">
						{{ t('attendance', 'Save') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { NcModal, NcButton, NcTextField, NcTextArea } from '@nextcloud/vue'

const props = defineProps({
	show: {
		type: Boolean,
		required: true,
	},
	appointment: {
		type: Object,
		default: null,
	},
})

const emit = defineEmits(['close', 'submit'])

const endDatetimePicker = ref(null)

const formData = reactive({
	name: '',
	description: '',
	startDatetime: '',
	endDatetime: '',
})

const isEdit = computed(() => !!props.appointment)

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

// Watch for appointment changes to populate form
watch(() => props.appointment, (newAppointment) => {
	if (newAppointment) {
		formData.name = newAppointment.name || ''
		formData.description = newAppointment.description || ''
		formData.startDatetime = formatDateTimeForInput(newAppointment.startDatetime)
		formData.endDatetime = formatDateTimeForInput(newAppointment.endDatetime)
	} else {
		// Reset form for create
		formData.name = ''
		formData.description = ''
		formData.startDatetime = ''
		formData.endDatetime = ''
	}
}, { immediate: true })

// Reset form when modal opens in create mode
watch(() => props.show, (isShowing) => {
	if (isShowing && !props.appointment) {
		// Modal opened in create mode - ensure form is reset
		formData.name = ''
		formData.description = ''
		formData.startDatetime = ''
		formData.endDatetime = ''
	}
})

const handleStartDateBlur = () => {
	// Auto-set endDatetime if it's empty and startDatetime is set
	if (formData.startDatetime && !formData.endDatetime) {
		const startDate = new Date(formData.startDatetime)
		const endDate = new Date(startDate.getTime() + 2.5 * 60 * 60 * 1000) // Add 2.5 hours
		formData.endDatetime = formatDateTimeForInput(endDate.toISOString())
	}
}

const handleClose = () => {
	emit('close')
}

const handleSubmit = () => {
	emit('submit', {
		id: props.appointment?.id,
		name: formData.name,
		description: formData.description,
		startDatetime: formData.startDatetime,
		endDatetime: formData.endDatetime,
	})
}
</script>

<style scoped lang="scss">
.modal-content {
	padding: 20px;
	min-width: 500px;
	max-height: 90vh;
	overflow-y: auto;
	
	h2 {
		margin-top: 0;
		margin-bottom: 20px;
	}
	
	form {
		display: flex;
		flex-direction: column;
		gap: 15px;
		
		// Override NcTextArea height
		:deep(.textarea__main-wrapper) {
			min-height: calc(var(--default-clickable-area) * 4);
		}
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
		
		input[type="datetime-local"] {
			width: 100%;
			padding: 10px;
			border: 2px solid var(--color-border-dark);
			border-radius: var(--border-radius);
			background-color: var(--color-main-background);
			color: var(--color-main-text);
			font-size: 14px;
			font-family: inherit;
			
			&:focus {
				border-color: var(--color-primary-element);
				outline: none;
			}
			
			&:hover {
				border-color: var(--color-primary-element-light);
			}
		}
	}
	
	.form-actions {
		display: flex;
		justify-content: flex-end;
		gap: 10px;
		margin-top: 10px;
	}
}
</style>
