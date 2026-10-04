<template>
	<NcDialog
		:name="t('attendance', 'Edit category')"
		size="normal"
		data-test="category-edit-dialog"
		@closing="emit('close')">
		<form class="category-dialog" @submit.prevent="submit">
			<section class="category-dialog__section">
				<h3>{{ t('attendance', 'Category & icon') }}</h3>
				<div class="category-dialog__name">
					<CategoryIconPicker v-model="draft.icon" data-test="input-edit-category-icon" />
					<NcInputField
						v-model="draft.name"
						:label="t('attendance', 'Category name')"
						:labelOutside="true"
						:aria-label="t('attendance', 'Category name')"
						data-test="input-edit-category-name" />
				</div>
			</section>

			<section class="category-dialog__section" data-test="category-template-section">
				<h3>{{ t('attendance', 'Template for this category') }}</h3>
				<p class="hint-text">
					{{ t('attendance', 'New appointments of this category start with these values. Empty fields and switches left at their usual setting are not prefilled, and every value can still be changed for each appointment.') }}
				</p>

				<NcTextField
					v-model="draft.templateName"
					:label="t('attendance', 'Appointment name')"
					data-test="input-category-template-name" />
				<MarkdownEditor
					v-model="draft.description"
					:label="t('attendance', 'Description')"
					:placeholder="t('attendance', 'Write your description here …')"
					minHeight="100px"
					data-test="input-category-template-description" />
				<NcTextField
					v-model="draft.location"
					:label="t('attendance', 'Location')"
					data-test="input-category-template-location" />

				<div class="category-dialog__field">
					<span class="category-dialog__label">{{ t('attendance', 'Response deadline') }}</span>
					<div class="category-dialog__deadline">
						<NcTextField
							v-model="draft.deadlineValue"
							type="number"
							min="1"
							:label="t('attendance', 'Number')"
							data-test="input-category-template-deadline-value" />
						<select
							v-model="draft.deadlineUnit"
							class="category-dialog__select"
							:aria-label="t('attendance', 'Unit')"
							data-test="select-category-template-deadline-unit">
							<option v-for="unit in deadlineUnits" :key="unit.value" :value="unit.value">
								{{ unit.label }}
							</option>
						</select>
						<span class="category-dialog__suffix">{{ t('attendance', 'before each appointment starts') }}</span>
					</div>
				</div>

				<NcTextField
					v-model="draft.maxAttendees"
					type="number"
					min="1"
					:label="t('attendance', 'Maximum attendees')"
					:placeholder="t('attendance', 'Unlimited')"
					data-test="input-category-template-limit" />
				<NcCheckboxRadioSwitch
					v-if="hasLimit"
					v-model="draft.waitlistEnabled"
					data-test="checkbox-category-template-waitlist">
					{{ t('attendance', 'Offer a waitlist when full') }}
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					v-model="draft.allowMaybe"
					data-test="checkbox-category-template-maybe">
					{{ t('attendance', 'Offer “Maybe” as an answer') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="draft.sendNotification"
					data-test="checkbox-category-template-notify">
					{{ t('attendance', 'Send notification') }}
				</NcCheckboxRadioSwitch>

				<AudienceSelect
					v-model="draft.audience"
					:inputLabel="t('attendance', 'Restrict access')"
					data-test="select-category-template-access" />
			</section>
		</form>

		<template #actions>
			<NcButton variant="tertiary" @click="emit('close')">
				{{ t('attendance', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!draft.name.trim() || saving"
				data-test="button-save-category"
				@click="submit">
				{{ t('attendance', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcInputField, NcTextField } from '@nextcloud/vue'
import { computed, defineAsyncComponent, reactive } from 'vue'
import AudienceSelect from '../common/AudienceSelect.vue'
import CategoryIconPicker from './CategoryIconPicker.vue'
import { toAudienceIds, toAudienceItems } from '../../utils/audience.js'

const props = defineProps({
	category: {
		type: Object,
		required: true,
	},
	saving: {
		type: Boolean,
		default: false,
	},
	// The instance-wide "Maybe" setting, which is what a new appointment starts with.
	instanceAllowsMaybe: {
		type: Boolean,
		required: true,
	},
})

const emit = defineEmits(['close', 'save'])

// Only loaded while the dialog is open — the editor is too heavy to ship with the page.
const MarkdownEditor = defineAsyncComponent(() => import('../common/MarkdownEditor.vue'))

const deadlineUnits = computed(() => [
	{ value: 'minutes', label: t('attendance', 'minutes') },
	{ value: 'hours', label: t('attendance', 'hours') },
	{ value: 'days', label: t('attendance', 'days') },
	{ value: 'weeks', label: t('attendance', 'weeks') },
])

// A switch starts like it does on a new appointment, and only a deviation is
// stored — a checkbox cannot say "not preset", the usual setting says it.
const DEFAULTS = { allowMaybe: props.instanceAllowsMaybe, sendNotification: false, waitlistEnabled: true }
function fromStored(value, usual) {
	return typeof value === 'boolean' ? value : usual
}
function toStored(value, usual) {
	return value === usual ? null : value
}
function toInt(value) {
	const parsed = Number.parseInt(value, 10)
	return Number.isInteger(parsed) && parsed >= 1 ? parsed : null
}

const template = props.category.template
const draft = reactive({
	name: props.category.name,
	icon: props.category.icon,
	templateName: template?.name ?? '',
	description: template?.description ?? '',
	location: template?.location ?? '',
	deadlineValue: template?.responseDeadlineValue ? String(template.responseDeadlineValue) : '',
	deadlineUnit: template?.responseDeadlineUnit ?? 'days',
	maxAttendees: template?.maxAttendees ? String(template.maxAttendees) : '',
	waitlistEnabled: fromStored(template?.waitlistEnabled, DEFAULTS.waitlistEnabled),
	allowMaybe: fromStored(template?.allowMaybe, DEFAULTS.allowMaybe),
	sendNotification: fromStored(template?.sendNotification, DEFAULTS.sendNotification),
	audience: toAudienceItems(template),
})

const hasLimit = computed(() => toInt(draft.maxAttendees) !== null)

function submit() {
	if (!draft.name.trim() || props.saving) {
		return
	}
	const maxAttendees = toInt(draft.maxAttendees)
	const deadlineValue = toInt(draft.deadlineValue)
	emit('save', {
		id: props.category.id,
		name: draft.name.trim(),
		icon: draft.icon,
		template: {
			name: draft.templateName.trim(),
			description: draft.description,
			location: draft.location.trim(),
			responseDeadlineValue: deadlineValue,
			responseDeadlineUnit: deadlineValue === null ? null : draft.deadlineUnit,
			maxAttendees,
			waitlistEnabled: maxAttendees === null ? null : toStored(draft.waitlistEnabled, DEFAULTS.waitlistEnabled),
			allowMaybe: toStored(draft.allowMaybe, DEFAULTS.allowMaybe),
			sendNotification: toStored(draft.sendNotification, DEFAULTS.sendNotification),
			...toAudienceIds(draft.audience),
		},
	})
}
</script>

<style scoped lang="scss">
.category-dialog {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding-bottom: 8px;

	&__section {
		display: flex;
		flex-direction: column;
		gap: 12px;

		h3 {
			margin: 0;
			font-size: 16px;
			font-weight: 600;
		}

		& + & {
			padding-top: 20px;
			border-top: 1px solid var(--color-border);
		}
	}

	&__name {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__label {
		font-weight: 600;
	}

	&__deadline {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__select {
		min-height: var(--default-clickable-area);
		padding: 0 12px;
		border: 2px solid var(--color-border-maxcontrast);
		border-radius: var(--border-radius-element);
		background: var(--color-main-background);
		color: var(--color-main-text);
	}

	&__suffix {
		color: var(--color-text-maxcontrast);
	}
}

.hint-text {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
