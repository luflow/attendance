<template>
	<div class="response-row" :data-test="`response-row-${response.userId}`">
		<div class="response-row__head">
			<div class="response-row__user">
				<ResponseDot :response="response.response" />
				<strong>{{ response.userName }}</strong>
				<RemindUserPopover
					v-if="canRemind"
					:userId="response.userId"
					:displayName="response.userName"
					:pending="isReminding"
					@remind="emit('remind', $event)">
					<template #default="{ pending }">
						<NcButton
							class="response-row__action"
							variant="secondary"
							size="small"
							:disabled="pending"
							:data-test="`remind-${response.userId}`"
							@click.stop>
							<template #icon>
								<BellRingOutlineIcon :size="16" />
							</template>
							<!-- TRANSLATORS: Button next to a "maybe" answer — nudges that one person to make up their mind. -->
							{{ t('attendance', 'Remind') }}
						</NcButton>
					</template>
				</RemindUserPopover>
				<NcButton
					v-if="canManageBooking && response.response === 'yes'"
					class="response-row__action response-row__booking"
					:variant="isBooked ? 'success' : 'secondary'"
					size="small"
					:disabled="isTogglingBooking || isClosed"
					:title="bookingToggleTitle"
					:data-test="`booking-toggle-${response.userId}`"
					@click="emit('toggleBooking', response)">
					<template #icon>
						<CalendarCheckIcon v-if="isBooked" :size="16" />
						<CalendarCheckOutlineIcon v-else :size="16" />
					</template>
					{{ bookingLabel }}
				</NcButton>
				<!-- Icon-only on purpose: the row already carries up to two labelled
					buttons, so the on-behalf editor stays as light as possible. -->
				<SetAnswerPopover
					v-if="canSetAnswer"
					:userId="response.userId"
					:displayName="response.userName"
					:currentResponse="response.response"
					:pending="isSettingAnswer"
					@setAnswer="(userId, value) => emit('setAnswer', userId, value)">
					<template #default="{ pending }">
						<NcButton
							class="response-row__action"
							variant="tertiary"
							size="small"
							:disabled="pending"
							:aria-label="setAnswerLabel"
							:title="setAnswerLabel"
							:data-test="`set-answer-${response.userId}`">
							<template #icon>
								<PencilOutlineIcon :size="16" />
							</template>
						</NcButton>
					</template>
				</SetAnswerPopover>
			</div>
			<div v-if="response.isCheckedIn" class="response-row__checkin">
				<span>{{ t("attendance", "Checked in?") }}</span>
				<ResponseDot :response="response.checkinState" />
			</div>
		</div>
		<div
			v-if="canSeeComments && response.comment && response.comment.trim()"
			class="response-row__comment">
			<CommentIcon :size="13" />
			<span>{{ response.comment }}</span>
		</div>
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import { computed } from 'vue'
import BellRingOutlineIcon from 'vue-material-design-icons/BellRingOutline.vue'
import CalendarCheckIcon from 'vue-material-design-icons/CalendarCheck.vue'
import CalendarCheckOutlineIcon from 'vue-material-design-icons/CalendarCheckOutline.vue'
import CommentIcon from 'vue-material-design-icons/Comment.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import RemindUserPopover from './RemindUserPopover.vue'
import ResponseDot from './ResponseDot.vue'
import SetAnswerPopover from './SetAnswerPopover.vue'

const props = defineProps({
	response: {
		type: Object,
		required: true,
	},
	canSeeComments: {
		type: Boolean,
		default: true,
	},
	canSendReminders: {
		type: Boolean,
		default: false,
	},
	canManageBooking: {
		type: Boolean,
		default: false,
	},
	isClosed: {
		type: Boolean,
		default: false,
	},
	canSetAnswer: {
		type: Boolean,
		default: false,
	},
	remindingUsers: {
		type: Set,
		default: () => new Set(),
	},
	togglingBooking: {
		type: Set,
		default: () => new Set(),
	},
	settingAnswer: {
		type: Set,
		default: () => new Set(),
	},
})

const emit = defineEmits(['remind', 'toggleBooking', 'setAnswer'])

// Nudging only makes sense for the undecided — a "yes" or "no" is already final.
const canRemind = computed(() => props.canSendReminders && props.response.response === 'maybe')
const isReminding = computed(() => props.remindingUsers.has(props.response.userId))
const isSettingAnswer = computed(() => props.settingAnswer.has(props.response.userId))
// TRANSLATORS: Tooltip/aria-label on the icon button that lets a manager record the person's answer on their behalf.
const setAnswerLabel = computed(() => t('attendance', 'Set answer'))
const isTogglingBooking = computed(() => props.togglingBooking.has(props.response.userId))
const isBooked = computed(() => props.response.bookingStatus === 'booked')
const bookingLabel = computed(() => {
	if (isBooked.value) {
		// TRANSLATORS: Status label on the per-person scheduling toggle — the person got a place in the appointment (German "Eingeplant", not "Geplant").
		return t('attendance', 'Scheduled')
	}
	// TRANSLATORS: Action label on the per-person scheduling toggle — the manager gives the person a place in the appointment ("schedule someone in", German "Einplanen" — not "Planen": the appointment itself is not being planned).
	return t('attendance', 'Schedule')
})

const bookingToggleTitle = computed(() => {
	if (!props.isClosed) {
		return null
	}
	// TRANSLATORS: Tooltip on the disabled scheduling toggle. "scheduling" = the feature of giving people a place in the appointment (German: the noun is "Planung", the per-person action is "einplanen").
	return t('attendance', 'Reopen the inquiry to change scheduling')
})
</script>

<style scoped lang="scss">
.response-row {
    padding: 9px 2px;
    border-bottom: 1px solid var(--color-border);

    &:last-child {
        border-bottom: none;
    }

    &__head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    &__user {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;

        strong {
            font-size: 14px;
        }
    }

    // NcButton hugs the icon to the left edge (padding-inline: 4px 12px), which
    // reads as a stray gap on the right once the button has a visible surface.
    // Compound with .button-vue to outrank NcButton's own padding rule.
    &__action.button-vue {
        padding-inline-start: 10px;
    }

    &__checkin {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 13px;
        color: var(--color-text-maxcontrast);
    }

    &__comment {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        font-size: 13px;
        color: var(--color-text-maxcontrast);
        font-style: italic;
        padding-top: 5px;
    }
}
</style>
