<template>
	<div class="response-editor" :class="{ 'response-editor--compact': compact }">
		<div class="response-editor__buttons" :class="{ 'has-response': userResponse }">
			<slot name="label" />
			<div class="response-editor__options">
				<NcButton
					v-for="option in options"
					:key="option.value"
					:class="{ active: userResponse === option.value }"
					:variant="option.variant"
					:size="buttonSize"
					:disabled="responseCooldown"
					:data-test="`response-${option.value}`"
					@click="handleResponse(option.value)">
					<!-- Compact rows (widget, list card) stay text-only so the
					     whole group fits narrow screens on a single line. -->
					<template v-if="!compact" #icon>
						<component :is="option.icon" :size="16" />
					</template>
					{{ option.label }}
				</NcButton>
				<NcButton
					v-if="userResponse"
					class="comment-toggle"
					:class="{ 'comment-active': commentExpanded }"
					variant="tertiary"
					:size="buttonSize"
					:title="t('attendance', 'Comment (optional)')"
					data-test="button-toggle-comment"
					@click="toggleComment">
					<template #icon>
						<CommentIcon :size="compact ? 17 : 20" />
					</template>
				</NcButton>
			</div>
			<slot name="trailing" />
		</div>

		<div v-if="commentExpanded" class="response-editor__comment">
			<NcInputField
				ref="commentInput"
				v-model="localComment"
				type="text"
				:label="t('attendance', 'Comment (optional)')"
				:placeholder="commentPlaceholder"
				data-test="response-comment"
				@keydown.enter="saveComment" />
			<NcButton
				variant="secondary"
				:disabled="!commentChanged || savingComment"
				data-test="button-save-comment"
				@click="saveComment">
				<!-- TRANSLATORS: Button next to the comment field on an appointment — stores what was typed. -->
				{{ t("attendance", "Save") }}
			</NcButton>
		</div>

		<div
			v-if="formattedDeadline"
			class="response-editor__deadline"
			data-test="deadline-info">
			<ClockIcon :size="15" />
			<span>{{ t("attendance", "Responses possible until {when}", { when: formattedDeadline }) }}</span>
		</div>
	</div>
</template>

<script setup>
import { NcButton, NcInputField } from '@nextcloud/vue'
import { computed, nextTick, ref, watch } from 'vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ClockIcon from 'vue-material-design-icons/Clock.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import { useAppointmentResponse, useResponseCooldown } from '../../composables/useAppointmentResponse.js'
import { formatDateTime } from '../../utils/datetime.js'
import { getResponseIcon, getResponseText, getResponseVariant, RESPONSE_ORDER } from '../../utils/response.js'

const props = defineProps({
	appointmentId: {
		type: Number,
		required: true,
	},
	userResponse: {
		type: String,
		default: null,
	},
	comment: {
		type: String,
		default: '',
	},
	responseDeadline: {
		type: String,
		default: null,
	},
	// The list card renders the same controls at a smaller scale.
	compact: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['submitResponse'])

// Labels, variants and glyphs all come from the shared response helpers, so
// the buttons carry the same filled circles the sidebar uses.
const ICONS = { CheckCircle, HelpCircle, CloseCircle }
const options = computed(() => RESPONSE_ORDER.map((value) => ({
	value,
	label: getResponseText(value),
	variant: getResponseVariant(value),
	icon: ICONS[getResponseIcon(value)],
})))

const buttonSize = computed(() => (props.compact ? 'small' : 'normal'))

const formattedDeadline = computed(() => (props.responseDeadline ? formatDateTime(props.responseDeadline) : ''))

const { responseCooldown, resolveNext, startCooldown } = useResponseCooldown(() => props.userResponse)

function handleResponse(response) {
	if (responseCooldown.value) return
	startCooldown()
	emit('submitResponse', props.appointmentId, resolveNext(response))
}

// The extractor needs the NBSP before the ellipsis (Nextcloud l10n rule).
const commentPlaceholder = t('attendance', 'Add your comment\u00A0…')

const localComment = ref(props.comment)
// An existing comment is content, not a setting — show it on the detail card
// instead of hiding it behind a toggle. The list card stays collapsed so a long
// list does not grow a paragraph per row.
const commentExpanded = ref(Boolean(props.comment) && !props.compact)
const commentInput = ref(null)

const { savingComment, saveComment: saveCommentApi } = useAppointmentResponse()

// Saving a comment does not refresh the parent, so props.comment stays on the
// value the card was rendered with. Without a local baseline the Save button
// springs back to enabled a moment after a successful save and reads as
// "still unsaved".
const savedComment = ref(null)

const commentChanged = computed(() => localComment.value !== (savedComment.value ?? props.comment ?? ''))

async function toggleComment() {
	commentExpanded.value = !commentExpanded.value
	if (commentExpanded.value) {
		await nextTick()
		commentInput.value?.$el?.querySelector('input')?.focus()
	}
}

// Adopt server state only while the field is untouched, so a background
// refresh never overwrites what the user is in the middle of typing.
watch(() => props.comment, (next) => {
	if (!commentChanged.value) {
		savedComment.value = null
		localComment.value = next || ''
	}
})

async function saveComment() {
	if (!commentChanged.value || savingComment.value) return
	const pending = localComment.value
	const saved = await saveCommentApi(props.appointmentId, props.userResponse, pending)
	if (saved) {
		savedComment.value = pending
	}
}
</script>

<style scoped lang="scss">
.response-editor {
    display: flex;
    flex-direction: column;
    gap: 12px;

    &__buttons {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;

        // The label may wrap onto its own line, but the answer buttons and the
        // comment toggle always stay together as one unit.
        .response-editor__options {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
        }

        // With a response given, the options not taken step back visually.
        &.has-response {
            :deep(.button-vue:not(.active):not(.comment-toggle)) {
                background-color: var(--color-background-dark) !important;
                color: var(--color-text-maxcontrast) !important;
                border-color: var(--color-border-dark) !important;

                &:hover {
                    background-color: var(--color-background-hover) !important;
                    color: var(--color-main-text) !important;
                }
            }
        }

        :deep(.button-vue.active) {
            font-weight: bold;
        }

        :deep(.button-vue.comment-active) {
            background-color: var(--color-primary-element) !important;
            color: var(--color-primary-element-text) !important;
        }
    }

    &__comment {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        max-width: 560px;

        // The field carries a floating label, so it is taller than the button;
        // align on the baseline of the two boxes rather than centring.
        :deep(.input-field) {
            flex: 1;
            min-width: 0;
        }
    }

    &__deadline {
        display: flex;
        align-items: center;
        gap: 6px;
        color: var(--color-text-maxcontrast);
        font-size: 0.9em;
    }

    &--compact {
        gap: 10px;
    }
}
</style>
