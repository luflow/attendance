<template>
	<div class="permission-row" :data-test="dataTest">
		<h5 class="permission-row__title">
			{{ title }}
		</h5>
		<p class="permission-row__hint">
			{{ hint }}
		</p>
		<div class="permission-row__modes">
			<NcCheckboxRadioSwitch v-for="mode in MODES"
				:key="mode.value"
				:modelValue="modelValue.mode"
				:value="mode.value"
				:name="`${dataTest}-mode`"
				type="radio"
				buttonVariant
				buttonVariantGrouped="horizontal"
				:data-test="`${dataTest}-mode-${mode.value}`"
				@update:modelValue="setMode">
				{{ mode.label }}
			</NcCheckboxRadioSwitch>
		</div>
		<template v-if="modelValue.mode === 'groups'">
			<GroupSelect
				:modelValue="modelValue.groups"
				:options="options"
				:placeholder="t('attendance', 'Select groups …')"
				:data-test="`${dataTest}-groups`"
				@update:modelValue="setGroups" />
			<p v-if="modelValue.groups.length === 0" class="permission-row__note permission-row__note--warning">
				{{ t('attendance', 'No groups selected yet — currently nobody is granted this.') }}
			</p>
		</template>
		<p v-if="warningWhenAll && modelValue.mode === 'all'" class="permission-row__note permission-row__note--warning">
			<AlertIcon :size="16" />
			{{ warningWhenAll }}
		</p>
		<p v-if="implication" class="permission-row__note">
			<InformationIcon :size="16" />
			<span>
				{{ implicationParts[0]
				}}<SectionLink v-if="implicationLink"
					v-bind="implicationLink"
					@navigate="$emit('navigate', $event)" />{{ implicationParts[1] }}
			</span>
		</p>
	</div>
</template>

<script setup>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { computed } from 'vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import InformationIcon from 'vue-material-design-icons/InformationOutline.vue'
import GroupSelect from '../common/GroupSelect.vue'
import SectionLink from './SectionLink.vue'

const props = defineProps({
	title: {
		type: String,
		required: true,
	},
	hint: {
		type: String,
		required: true,
	},
	/** { mode: 'all'|'groups'|'nobody', groups: Array<{id, displayName}> } */
	modelValue: {
		type: Object,
		required: true,
	},
	options: {
		type: Array,
		default: () => [],
	},
	/** Rendered as an info note, e.g. inherited grants ("managers always …") */
	implication: {
		type: String,
		default: '',
	},
	/** Turns the `{section}` placeholder of the implication into a link: { label, sectionId } */
	implicationLink: {
		type: Object,
		default: null,
	},
	/** Warning note shown while the "all users" mode is selected */
	warningWhenAll: {
		type: String,
		default: '',
	},
	dataTest: {
		type: String,
		required: true,
	},
})

const emit = defineEmits(['update:modelValue', 'navigate'])

const implicationParts = computed(() => props.implication.split('{section}'))

const MODES = [
	{ value: 'all', label: t('attendance', 'All users') },
	{ value: 'groups', label: t('attendance', 'Specific groups') },
	{ value: 'nobody', label: t('attendance', 'Nobody') },
]

function setMode(mode) {
	emit('update:modelValue', { ...props.modelValue, mode })
}

function setGroups(groups) {
	emit('update:modelValue', { ...props.modelValue, groups })
}
</script>

<style scoped>
.permission-row {
	margin: 20px 0;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
}

.permission-row:last-child {
	border-bottom: none;
	padding-bottom: 0;
}

.permission-row__title {
	margin: 0 0 4px 0;
	font-size: 15px;
	font-weight: 600;
}

.permission-row__hint {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.permission-row__modes {
	display: flex;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.permission-row__note {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.permission-row__note--warning {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}
</style>
