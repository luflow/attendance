<template>
	<div v-if="modelValue.length > 1">
		<p class="ordered-selection__hint">
			{{ t('attendance', 'Sections appear in this order. Drag an entry or use the arrows to move it.') }}
		</p>
		<ul class="ordered-selection"
			:data-test="dataTest"
			@dragover="onListDragOver"
			@drop.prevent="onDrop">
			<li v-for="(row, index) in rows"
				:key="row.item.id"
				class="ordered-selection__item"
				:class="{ 'ordered-selection__item--ghost': row.item.id === draggedId }"
				draggable="true"
				:data-test="`${dataTest}-item`"
				@dragstart="onDragStart(row.item.id, $event)"
				@dragover="onDragOver(index)"
				@dragend="resetDrag">
				<DragIcon class="ordered-selection__handle" :size="20" />
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

const draggedId = ref(null)
// Order shown while dragging: the dragged entry already sits where it would land.
const preview = ref(null)

const rows = computed(() => (preview.value ?? props.modelValue).map((item) => {
	const label = props.formatLabel(item)
	return {
		item,
		label,
		moveUp: t('attendance', 'Move {name} up', { name: label }),
		moveDown: t('attendance', 'Move {name} down', { name: label }),
	}
}))

function moved(items, from, to) {
	const result = [...items]
	const [entry] = result.splice(from, 1)
	result.splice(to, 0, entry)
	return result
}

function reorder(from, to) {
	emit('update:modelValue', moved(props.modelValue, from, to))
}

function onDragStart(id, event) {
	draggedId.value = id
	preview.value = [...props.modelValue]
	event.dataTransfer.effectAllowed = 'move'
	// Firefox only starts a drag once some data is attached.
	event.dataTransfer.setData('text/plain', '')
}

function onListDragOver(event) {
	// Only accept drops for our own drag, not for files dragged over the page.
	if (preview.value !== null) event.preventDefault()
}

function onDragOver(index) {
	if (preview.value === null) return
	const from = preview.value.findIndex((item) => item.id === draggedId.value)
	if (from !== -1 && from !== index) preview.value = moved(preview.value, from, index)
}

function onDrop() {
	if (preview.value !== null && preview.value.some((item, index) => item !== props.modelValue[index])) {
		emit('update:modelValue', preview.value)
	}
	resetDrag()
}

function resetDrag() {
	draggedId.value = null
	preview.value = null
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

.ordered-selection__item--ghost {
	opacity: 0.5;
	border-style: dashed;
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.ordered-selection__handle {
	color: var(--color-text-maxcontrast);
}

.ordered-selection__label {
	flex: 1 1 auto;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
