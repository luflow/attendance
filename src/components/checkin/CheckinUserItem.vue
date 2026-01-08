<template>
	<div class="user-item" :data-test="`user-item-${user.userId}`">
		<!-- Normal view when not in comment mode -->
		<template v-if="!showCommentInput">
			<div class="user-info">
				<NcAvatar :user="user.userId" :size="80" :show-user-status="false" />
				<div class="user-details">
					<div class="user-name">
						{{ user.displayName }}
					</div>
					<div class="response-row">
						<NcChip
							v-if="user.response"
							:text="getResponseText(user.response)"
							:variant="getResponseVariant(user.response)"
							no-close />
						<NcChip
							v-else
							:text="t('attendance', 'No Response')"
							variant="tertiary"
							no-close />
					</div>
					<div v-if="canSeeComments && user.comment && user.comment.trim()" class="user-comment">
						<CommentIcon :size="14" class="comment-icon" />
						{{ user.comment }}
					</div>
				</div>
			</div>
			<div class="user-actions">
				<div class="action-buttons">
					<NcButton
						:variant="user.checkinState === 'yes' || !user.checkinState ? 'success' : 'tertiary'"
						data-test="button-present"
						@click="$emit('checkin', user.userId, 'yes')">
						{{ t('attendance', 'Present') }}
					</NcButton>
					<NcButton
						:variant="user.checkinState === 'no' || !user.checkinState ? 'error' : 'tertiary'"
						data-test="button-absent"
						@click="$emit('checkin', user.userId, 'no')">
						{{ t('attendance', 'Absent') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						data-test="button-add-comment"
						:aria-label="t('attendance', 'Add comment')"
						@click="$emit('toggle-comment', user.userId)">
						<template #icon>
							<CommentIcon :size="16" />
						</template>
					</NcButton>
				</div>
				<!-- Check-in comment below buttons -->
				<div v-if="user.checkinComment && user.checkinComment.trim()" class="checkin-comment">
					<CommentIcon :size="14" class="checkin-icon" />
					{{ user.checkinComment }}
				</div>
			</div>
		</template>

		<!-- Comment overlay mode -->
		<template v-if="showCommentInput">
			<div class="comment-overlay">
				<div class="comment-overlay-header">
					<NcAvatar :user="user.userId" :size="32" :show-user-status="false" />
					<div class="comment-overlay-info">
						<div class="user-name">
							{{ user.displayName }}
						</div>
						<div class="response-row">
							<NcChip
								v-if="user.response"
								:text="getResponseText(user.response)"
								:variant="getResponseVariant(user.response)"
								no-close />
							<NcChip
								v-else
								:text="t('attendance', 'No Response')"
								variant="tertiary"
								no-close />
						</div>
					</div>
				</div>
				<div class="comment-overlay-input">
					<NcTextArea
						:value="commentValue"
						:label="t('attendance', 'Check-in comment')"
						:placeholder="t('attendance', 'Add a comment for this check-in…')"
						data-test="textarea-checkin-comment"
						rows="2"
						@update:value="$emit('update:commentValue', $event)" />
					<div class="comment-actions">
						<NcButton variant="primary" data-test="button-save-comment" @click="$emit('save-comment', user.userId)">
							{{ t('attendance', 'Save') }}
						</NcButton>
						<NcButton variant="tertiary" data-test="button-cancel-comment" @click="$emit('cancel-comment', user.userId)">
							{{ t('attendance', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { NcButton, NcAvatar, NcChip, NcTextArea } from '@nextcloud/vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import { getResponseText, getResponseVariant } from '../../utils/response.js'

defineProps({
	user: {
		type: Object,
		required: true,
	},
	showCommentInput: {
		type: Boolean,
		default: false,
	},
	commentValue: {
		type: String,
		default: '',
	},
	canSeeComments: {
		type: Boolean,
		default: true,
	},
})

defineEmits(['checkin', 'toggle-comment', 'save-comment', 'cancel-comment', 'update:commentValue'])
</script>

<style scoped lang="scss">
.user-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);

	.user-info {
		display: flex;
		align-items: center;
		gap: 12px;
		flex: 1;

		.user-details {
			flex: 1;
			margin-left: 12px;

			.user-name {
				font-weight: 600;
				color: var(--color-main-text);
				margin-bottom: 4px;
			}

			.response-row {
				margin-bottom: 5px;
			}

			.user-comment {
				font-size: 12px;
				color: var(--color-text-maxcontrast);
				font-style: italic;
				margin: 4px 0;
				display: flex;
				align-items: center;
				gap: 6px;

				.comment-icon {
					color: var(--color-text-maxcontrast);
					flex-shrink: 0;
				}
			}
		}
	}

	.checkin-comment {
		font-size: 12px;
		color: var(--color-primary);
		margin: 0;
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 6px;

		.checkin-icon {
			color: var(--color-primary);
			flex-shrink: 0;
		}
	}

	.user-actions {
		display: flex;
		flex-direction: column;
		gap: 8px;
		flex-shrink: 0;

		.action-buttons {
			display: flex;
			gap: 8px;

			@media (max-width: 768px) {
				flex-direction: column;
			}
		}
	}

	.comment-overlay {
		display: flex;
		align-items: flex-start;
		gap: 16px;
		padding: 12px;
		background: var(--color-background-hover);
		border-radius: var(--border-radius-large);
		border: 2px solid var(--color-primary-element);
		width: 100%;

		.comment-overlay-header {
			display: flex;
			align-items: center;
			gap: 12px;
			flex-shrink: 0;

			.comment-overlay-info {
				display: flex;
				flex-direction: column;
				gap: 4px;

				.user-name {
					font-weight: 600;
					color: var(--color-main-text);
				}
			}
		}

		.comment-overlay-input {
			flex: 1;

			.comment-actions {
				display: flex;
				gap: 8px;
				justify-content: flex-end;
				margin-top: 8px;
			}
		}
	}
}
</style>
