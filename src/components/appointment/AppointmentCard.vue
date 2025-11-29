<template>
	<div class="appointment-card">
		<div class="appointment-header">
			<h3>{{ appointment.name }}</h3>
			<div class="appointment-actions">
				<NcActions :force-menu="true">
					<NcActionButton @click="copyShareLink" :close-after-click="true">
						<template #icon>
							<ShareVariantIcon :size="20" />
						</template>
						{{ t('attendance', 'Share Link') }}
					</NcActionButton>
					<NcActionButton v-if="canCheckin" @click="handleStartCheckin" :close-after-click="true">
						<template #icon>
							<ListStatusIcon :size="20" />
						</template>
						{{ t('attendance', 'Start check-in') }}
					</NcActionButton>
					<NcActionButton v-if="canManageAppointments" @click="handleEdit" :close-after-click="true">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('attendance', 'Edit') }}
					</NcActionButton>
					<NcActionButton v-if="canManageAppointments" @click="handleDelete" :close-after-click="true">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('attendance', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>
		
		<p v-if="appointment.description" class="appointment-description" v-html="renderedDescription"></p>
		
		<div class="appointment-time">
			<strong>{{ t('attendance', 'Start Date & Time') }}:</strong> {{ formatDateTime(appointment.startDatetime) }}<br>
			<strong>{{ t('attendance', 'End Date & Time') }}:</strong> {{ formatDateTime(appointment.endDatetime) }}
		</div>

		<!-- Response Section -->
		<div class="response-section">
			<h4>{{ t('attendance', 'Your Response') }}</h4>
			<div class="response-buttons" :class="{ 'has-response': userResponse }">
				<NcButton 
					:class="{ active: userResponse === 'yes' }" 
					variant="success"
					:text="t('attendance', 'Yes')"
					@click="handleResponse('yes')" />
				<NcButton 
					:class="{ active: userResponse === 'maybe' }" 
					variant="warning"
					:text="t('attendance', 'Maybe')"
					@click="handleResponse('maybe')" />
				<NcButton 
					:class="{ active: userResponse === 'no' }" 
					variant="error"
					:text="t('attendance', 'No')"
					@click="handleResponse('no')" />
			</div>

			<!-- Comment Section -->
			<div v-if="userResponse" class="comment-section">
				<div class="textarea-container">
					<NcTextArea 
						resize="vertical"
						v-model="localComment"
						@input="handleCommentInputEvent"
						:placeholder="t('attendance', 'Comment (optional)')" />
					
					<div v-if="savingComment" class="saving-spinner">
						<div class="spinner"></div>
					</div>
					<div v-else-if="commentSaved" class="saved-indicator">
						<CheckIcon :size="16" class="check-icon" />
					</div>
				</div>
			</div>
		</div>

		<!-- Detailed Response Summary -->
		<ResponseSummary :response-summary="appointment.responseSummary" />
	</div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { NcButton, NcActions, NcActionButton, NcTextArea } from '@nextcloud/vue'
import ResponseSummary from './ResponseSummary.vue'
import { renderMarkdown, sanitizeHtml } from '../../utils/markdown.js'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
	canManageAppointments: {
		type: Boolean,
		default: false,
	},
	canCheckin: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['start-checkin', 'edit', 'delete', 'submit-response', 'update-comment'])

const localComment = ref(props.appointment.userResponse?.comment || '')
const savingComment = ref(false)
const commentSaved = ref(false)
let commentTimeout = null

const userResponse = computed(() => {
	return props.appointment.userResponse?.response || null
})

const renderedDescription = computed(() => {
	if (!props.appointment.description) return ''
	const html = renderMarkdown(props.appointment.description, false)
	return sanitizeHtml(html)
})

watch(() => props.appointment.userResponse, (newResponse) => {
	if (!commentTimeout) {
		localComment.value = newResponse?.comment || ''
	}
}, { immediate: true, deep: true })

const formatDateTime = (dateTime) => {
	const date = new Date(dateTime)
	return date.toLocaleString('de-DE', {
		day: '2-digit',
		month: '2-digit',
		year: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
	})
}

const copyShareLink = async () => {
	const appointmentUrl = window.location.origin + generateUrl(`/apps/attendance/appointment/${props.appointment.id}`)
	
	try {
		await navigator.clipboard.writeText(appointmentUrl)
		showSuccess(window.t('attendance', 'Link copied to clipboard'))
	} catch (error) {
		console.error('Failed to copy link:', error)
	}
}

const handleStartCheckin = () => {
	emit('start-checkin', props.appointment.id)
}

const handleEdit = () => {
	emit('edit', props.appointment)
}

const handleDelete = () => {
	emit('delete', props.appointment.id)
}

const handleResponse = (response) => {
	emit('submit-response', props.appointment.id, response)
}

const handleCommentInputEvent = () => {
	if (commentTimeout) {
		clearTimeout(commentTimeout)
	}

	commentTimeout = setTimeout(() => {
		autoSaveComment(localComment.value)
	}, 500)
}

const autoSaveComment = (commentText) => {
	if (!userResponse.value) return

	savingComment.value = true
	commentSaved.value = false

	emit('update-comment', props.appointment.id, commentText, true)

	setTimeout(() => {
		savingComment.value = false
		commentSaved.value = true
		
		setTimeout(() => {
			commentSaved.value = false
		}, 2000)
	}, 800)
}
</script>

<style scoped lang="scss">
.appointment-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.appointment-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 15px;

	h3 {
		margin: 0;
		font-size: 18px;
		color: var(--color-main-text);
		flex: 1;
	}

	.appointment-actions {
		margin-left: 10px;
	}
}

.appointment-description {
	color: var(--color-text-lighter);
	margin-bottom: 15px;
	white-space: pre-wrap;
	
	// Markdown formatting
	:deep(strong) {
		font-weight: bold;
		color: var(--color-main-text);
	}
	
	:deep(em) {
		font-style: italic;
	}
}

.appointment-time {
	padding: 10px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: 15px;
	font-size: 14px;

	strong {
		color: var(--color-main-text);
	}
}

.response-section {
	border-top: 1px solid var(--color-border);
	padding-top: 15px;
	margin-top: 15px;

	h4 {
		margin: 0 0 10px 0;
	}

	.response-buttons {
		display: flex;
		gap: 10px;
		margin-bottom: 15px;

		// When a response exists, gray out non-active buttons
		&.has-response {
			:deep(.button-vue:not(.active)) {
				background-color: var(--color-background-dark) !important;
				color: var(--color-text-lighter) !important;
				border-color: var(--color-border-dark) !important;

				&:hover {
					background-color: var(--color-background-hover) !important;
					color: var(--color-text) !important;
				}
			}
		}

		// Active button styles - keep bold
		:deep(.button-vue.active) {
			font-weight: bold;
		}
	}

	.comment-section {
		margin-top: 10px;

		.textarea-container {
			position: relative;
		}

		.saving-spinner,
		.saved-indicator {
			position: absolute;
			top: 15px;
			right: 15px;
			pointer-events: none;
			z-index: 10;
		}

		.saving-spinner {
			.spinner {
				width: 16px;
				height: 16px;
				border: 2px solid var(--color-border);
				border-top: 2px solid var(--color-primary);
				border-radius: 50%;
				animation: spin 1s linear infinite;
			}
		}

		.saved-indicator {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 16px;
			height: 16px;
			background-color: green;
			border-radius: 50%;
			animation: fadeIn 0.3s ease-in;

			.check-icon {
				color: white;
			}
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		@keyframes fadeIn {
			from { opacity: 0; transform: scale(0.5); }
			to { opacity: 1; transform: scale(1); }
		}
	}
}

</style>
