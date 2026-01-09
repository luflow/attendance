<template>
	<div class="appointment-item" data-test="widget-appointment-item">
		<!-- Check-in Section (for admins when appointment is ready for check-in) -->
		<div v-if="showCheckinButton" class="checkin-section">
			<NcButton
				variant="primary"
				class="checkin-button"
				data-test="button-widget-checkin"
				@click="$emit('open-checkin', item.id)">
				<template #icon>
					<ListStatusIcon />
				</template>
				{{ t('attendance', 'Start check-in') }}
			</NcButton>
		</div>

		<div class="appointment-header">
			<h3 class="clickable" data-test="widget-appointment-title" @click="$emit('open-detail', item.id)">
				{{ item.mainText }}
			</h3>
			<span class="appointment-time">{{ formattedDate }}</span>
		</div>

		<div v-if="item.description" class="appointment-description clickable" @click="$emit('open-detail', item.id)">
			{{ strippedDescription }}
		</div>

		<!-- Response Section -->
		<div class="response-section">
			<div class="response-buttons" :class="{ 'has-response': item.userResponse }">
				<NcButton
					:class="{ active: item.userResponse?.response === 'yes' }"
					variant="success"
					:text="t('attendance', 'Yes')"
					data-test="widget-response-yes"
					@click="$emit('respond', item.id, 'yes')" />
				<NcButton
					:class="{ active: item.userResponse?.response === 'maybe' }"
					variant="warning"
					:text="t('attendance', 'Maybe')"
					data-test="widget-response-maybe"
					@click="$emit('respond', item.id, 'maybe')" />
				<NcButton
					:class="{ active: item.userResponse?.response === 'no' }"
					variant="error"
					:text="t('attendance', 'No')"
					data-test="widget-response-no"
					@click="$emit('respond', item.id, 'no')" />
				<!-- Comment Toggle Button (only show when user has responded) -->
				<NcButton
					v-if="item.userResponse"
					:class="{ 'comment-active': commentExpanded, 'comment-toggle': true }"
					type="tertiary"
					data-test="button-widget-toggle-comment"
					@click="toggleComment">
					<template #icon>
						<CommentIcon :size="14" />
					</template>
				</NcButton>
			</div>

			<!-- Comment Section -->
			<div v-if="commentExpanded" class="comment-section">
				<div class="textarea-container">
					<NcInputField
						ref="commentInput"
						v-model="localComment"
						type="text"
						:label="t('attendance', 'Comment (optional)')"
						:placeholder="t('attendance', 'Add your comment …')"
						data-test="widget-response-comment"
						@update:model-value="handleCommentInput" />

					<div v-if="saving" class="saving-spinner">
						<div class="spinner" />
					</div>
					<div v-else-if="saved" class="saved-indicator">
						<CheckIcon :size="16" class="check-icon" />
					</div>
					<div v-else-if="error" class="error-indicator">
						<CloseCircle :size="16" class="error-icon" />
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { NcButton, NcInputField } from '@nextcloud/vue'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import { formatDateTime } from '../../utils/datetime.js'
import { stripMarkdown } from '../../utils/markdown.js'
import { useAppointmentResponse } from '../../composables/useAppointmentResponse.js'

const props = defineProps({
	item: {
		type: Object,
		required: true,
	},
	showCheckinButton: {
		type: Boolean,
		default: false,
	},
})

defineEmits(['respond', 'open-checkin', 'open-detail'])

// Local state for comment
const commentExpanded = ref(false)
const commentInput = ref(null)
const localComment = ref(props.item.userResponse?.comment || '')
let commentTimeout = null

// Use the shared response composable for comment auto-save
const {
	savingComment: saving,
	commentSaved: saved,
	errorComment: error,
	autoSaveComment,
} = useAppointmentResponse()

// Watch for external changes to userResponse
watch(() => props.item.userResponse, (newResponse) => {
	if (!commentTimeout) {
		localComment.value = newResponse?.comment || ''
	}
}, { deep: true })

const toggleComment = async () => {
	commentExpanded.value = !commentExpanded.value
	if (commentExpanded.value) {
		await nextTick()
		commentInput.value?.$el?.querySelector('input')?.focus()
	}
}

const handleCommentInput = () => {
	if (commentTimeout) {
		clearTimeout(commentTimeout)
	}

	commentTimeout = setTimeout(async () => {
		await nextTick()
		const userResponse = props.item.userResponse
		if (userResponse) {
			autoSaveComment(props.item.id, userResponse.response, localComment.value)
		}
		commentTimeout = null
	}, 500)
}

const formattedDate = computed(() => {
	return formatDateTime(props.item.subText)
})

const strippedDescription = computed(() => {
	return stripMarkdown(props.item.description)
})
</script>

<style scoped lang="scss">
@use '../../styles/shared.scss';

.appointment-item {
	padding: 0 14px 12px 14px;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
	word-wrap: break-word;

	&:last-child {
		border-bottom: none;
	}
}

.appointment-header {
	display: flex;
	justify-content: space-between;
	align-items: center;

	h3 {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		flex: 1;
		margin-right: 8px;

		&.clickable {
			cursor: pointer;

			&:hover {
				text-decoration: underline;
			}
		}
	}

	.appointment-time {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		flex-shrink: 0;
	}
}

.appointment-description {
	font-size: 12px;
	color: var(--color-text-light);
	margin-bottom: 8px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;

	&.clickable {
		cursor: pointer;

		&:hover {
			text-decoration: underline;
			color: var(--color-text-maxcontrast);
		}
	}
}

.checkin-section {
	margin-bottom: 8px;

	.checkin-button {
		font-size: 11px;
		padding: 4px 12px;
		min-height: 28px;
		font-weight: 600;
	}
}

.response-section {
	margin-top: 8px;

	.response-buttons {
		display: flex;
		gap: 6px;
		margin-bottom: 8px;
		flex-wrap: wrap;

		:deep(.button-vue) {
			font-size: 11px;
			padding: 4px 12px;
			height: 32px;
		}

		&.has-response {
			:deep(.button-vue:not(.active):not(.comment-toggle)) {
				background-color: var(--color-background-dark) !important;
				color: var(--color-text-lighter) !important;
				border-color: var(--color-border-dark) !important;

				&:hover {
					background-color: var(--color-background-hover) !important;
					color: var(--color-text) !important;
				}
			}
		}

		:deep(.button-vue.active) {
			font-weight: bold;
		}

		:deep(.button-vue.comment-active) {
			background-color: var(--color-primary-element) !important;
			color: white !important;
		}
	}

	.comment-section {
		margin-top: 16px;

		.textarea-container {
			position: relative;
		}
	}
}
</style>
