<template>
	<div v-if="modelValue.length > 1">
		<p class="ordered-selection__hint">
			{{ t('attendance', 'Sections appear in this order. Drag an entry or use the arrows to move it.') }}
		</p>
		<ul class="ordered-selection" :data-test="dataTest">
			<li v-for="(row, index) in rows"
				:key="row.item.id"
				class="ordered-selection__item"
				:class="{
					'ordered-selection__item--dragged': dragIndex === index,
					'ordered-selection__item--target': overIndex === index && dragIndex !== index,
				}"
				draggable="true"
				:data-test="`${dataTest}-item`"
				@dragstart="onDragStart(index, $event)"
				@dragover.prevent="overIndex = index"
				@dragleave="onDragLeave(index)"
				@drop.prevent="onDrop(index)"
				@dragend="resetDrag">
				<DragIcon class="ordered-selection__handle" :size="20" />
				<span class="ordered-selection__position">{{ index + 1 }}</span>
				<span class="ordered-selection__label">{{ row.label }}</span>
				<NcButton variant="tertiary"
					:disabled="index === 0"
					:aria-label="row.moveUp"
					:title="row.moveUp"
					:data-test="`${dataTest}-up`"
					@click="reorder(index, index - 1)">
					<template #icon>
						<ArrowUp :size="20" />
					</template>
				</NcButton>
				<NcButton variant="tertiary"
					:disabled="index === rows.length - 1"
					:aria-label="row.moveDown"
					:title="row.moveDown"
					:data-test="`${dataTest}-down`"
					@click="reorder(index, index + 1)">
					<template #icon>
						<ArrowDown :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import { computed, ref } from 'vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import DragIcon from 'vue-material-design-icons/DragHorizontalVariant.vue'

const props = defineProps({
	/** Ordered selection, each entry `{ id, … }` */
	modelValue: {
		type: Array,
		default: () => [],
	},
	formatLabel: {
		type: Function,
		required: true,
	},
	dataTest: {
		type: String,
		default: 'ordered-selection',
	},
})

const emit = defineEmits(['update:modelValue'])

const dragIndex = ref(null)
const overIndex = ref(null)

const rows = computed(() => props.modelValue.map((item) => {
	const label = props.formatLabel(item)
	return {
		item,
		label,
		moveUp: t('attendance', 'Move {name} up', { name: label }),
		moveDown: t('attendance', 'Move {name} down', { name: label }),
	}
}))

function reorder(from, to) {
	const items = [...props.modelValue]
	const [moved] = items.splice(from, 1)
	items.splice(to, 0, moved)
	emit('update:modelValue', items)
}

function onDragStart(index, event) {
	dragIndex.value = index
	event.dataTransfer.effectAllowed = 'move'
	// Firefox only starts a drag once some data is attached.
	event.dataTransfer.setData('text/plain', '')
}

function onDragLeave(index) {
	if (overIndex.value === index) overIndex.value = null
}

function onDrop(index) {
	if (dragIndex.value !== null && dragIndex.value !== index) {
		reorder(dragIndex.value, index)
	}
	resetDrag()
}

function resetDrag() {
	dragIndex.value = null
	overIndex.value = null
}
</script>

<style scoped>
.ordered-selection__hint {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.ordered-selection {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-block: 8px;
	max-width: 480px;
}

.ordered-selection__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border: 2px solid transparent;
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background-color: var(--color-background-hover);
	cursor: grab;
}

.ordered-selection__item--dragged {
	opacity: 0.5;
}

.ordered-selection__item--target {
	border-color: var(--color-primary-element);
}

.ordered-selection__handle {
	color: var(--color-text-maxcontrast);
}

.ordered-selection__position {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	min-width: 1.5em;
	text-align: end;
}

.ordered-selection__label {
	flex: 1 1 auto;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
