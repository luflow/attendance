<template>
	<section v-if="capabilities.auditLog" class="audit-timeline" data-test="audit-timeline">
		<h4 class="audit-timeline__heading">
			{{ t('attendance', 'Activity history') }}
		</h4>

		<div v-if="loading" class="audit-timeline__state">
			<NcLoadingIcon :size="20" />
			<span>{{ t('attendance', 'Loading …') }}</span>
		</div>

		<NcEmptyContent v-else-if="error"
			:name="t('attendance', 'Failed to load activity')"
			:description="t('attendance', 'Please try again later.')">
			<template #icon>
				<AlertCircle :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="formattedItems.length === 0"
			:name="t('attendance', 'No activity yet')"
			:description="t('attendance', 'Responses and check-ins will appear here.')">
			<template #icon>
				<History :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="audit-timeline__list">
			<li v-for="row in formattedItems"
				:key="row.id"
				class="audit-timeline__item"
				:data-test-verb="row.verb">
				<component :is="iconMap[row.icon] ?? Information" :size="20" class="audit-timeline__icon" />
				<div class="audit-timeline__body">
					<div class="audit-timeline__message">{{ row.message }}</div>
					<div class="audit-timeline__meta">
						<span class="audit-timeline__time">{{ formatDateTime(row.createdAt) }}</span>
						<span v-if="row.source" class="audit-timeline__source">{{ formatSource(row.source) }}</span>
					</div>
				</div>
			</li>
		</ul>

		<NcButton v-if="hasMore"
			variant="tertiary"
			class="audit-timeline__more"
			:disabled="loading"
			@click="loadMore">
			{{ t('attendance', 'Load older entries') }}
		</NcButton>
	</section>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import UndoVariant from 'vue-material-design-icons/UndoVariant.vue'
import CommentEdit from 'vue-material-design-icons/CommentEdit.vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import AccountSync from 'vue-material-design-icons/AccountSync.vue'
import Information from 'vue-material-design-icons/Information.vue'
import History from 'vue-material-design-icons/History.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { usePermissions } from '../../composables/usePermissions.js'
import { useAuditLog } from '../../composables/useAuditLog.js'
import { formatAuditEvent, formatSource } from '../../utils/auditFormat.js'
import { formatDateTime } from '../../utils/datetime.js'

const props = defineProps({
	appointmentId: {
		type: Number,
		required: true,
	},
})

const PAGE_SIZE = 50

const { capabilities } = usePermissions()
const { items, hasMore, loading, error, load } = useAuditLog()

const iconMap = {
	CheckCircleOutline,
	SwapHorizontal,
	UndoVariant,
	CommentEdit,
	AccountCheck,
	AccountSync,
	Information,
}

const formattedItems = computed(() => items.value.map(event => ({
	id: event.id,
	verb: event.verb,
	createdAt: event.createdAt,
	source: event.source,
	...formatAuditEvent(event),
})))

async function loadMore() {
	await load(props.appointmentId, { limit: PAGE_SIZE, offset: items.value.length, append: true })
}

onMounted(() => {
	load(props.appointmentId, { limit: PAGE_SIZE, offset: 0 })
})
</script>

<style scoped>
.audit-timeline {
	margin-top: 24px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.audit-timeline__heading {
	margin: 0 0 12px;
	font-size: 1rem;
	font-weight: 600;
}

.audit-timeline__state {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
}

.audit-timeline__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.audit-timeline__item {
	display: flex;
	align-items: flex-start;
	gap: 10px;
}

.audit-timeline__icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
	margin-top: 2px;
}

.audit-timeline__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.audit-timeline__message {
	color: var(--color-main-text);
	word-break: break-word;
}

.audit-timeline__meta {
	display: flex;
	gap: 8px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.audit-timeline__source::before {
	content: '·';
	margin-right: 8px;
}

.audit-timeline__more {
	margin-top: 12px;
}
</style>
