<template>
	<div class="pending-users">
		<div class="pending-users__header">
			{{ headerText }}
		</div>
		<div class="pending-users__chips">
			<span
				v-for="user in sortedUsers"
				:key="user.userId"
				class="pending-user"
				:class="{ 'pending-user--pending': remindingUsers.has(user.userId) }">
				{{ user.displayName }}
				<SetAnswerPopover
					v-if="canSetAnswer && appointmentId"
					:userId="user.userId"
					:displayName="user.displayName"
					:pending="settingAnswer.has(user.userId)"
					@setAnswer="(userId, value) => emit('setAnswer', userId, value)">
					<template #default="{ pending }">
						<NcButton
							variant="secondary"
							size="small"
							:disabled="pending"
							:aria-label="t('attendance', 'Set answer')"
							:title="t('attendance', 'Set answer')"
							:data-test="`set-answer-${user.userId}`">
							<template #icon>
								<PencilOutlineIcon :size="16" />
							</template>
						</NcButton>
					</template>
				</SetAnswerPopover>
				<RemindUserPopover
					v-if="canManageAppointments && appointmentId"
					:userId="user.userId"
					:displayName="user.displayName"
					:pending="remindingUsers.has(user.userId)"
					@remind="emit('remind', $event)">
					<template #default="{ pending }">
						<NcButton
							variant="secondary"
							size="small"
							:disabled="pending"
							:aria-label="t('attendance', 'Send reminder')"
							:title="t('attendance', 'Send reminder')"
							:data-test="`remind-${user.userId}`">
							<template #icon>
								<BellRingOutlineIcon :size="16" />
							</template>
						</NcButton>
					</template>
				</RemindUserPopover>
			</span>
		</div>
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import { computed } from 'vue'
import BellRingOutlineIcon from 'vue-material-design-icons/BellRingOutline.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import RemindUserPopover from './RemindUserPopover.vue'
import SetAnswerPopover from './SetAnswerPopover.vue'

const props = defineProps({
	users: {
		type: Array,
		required: true,
	},
	headerText: {
		type: String,
		default: '',
	},
	canManageAppointments: {
		type: Boolean,
		default: false,
	},
	canSetAnswer: {
		type: Boolean,
		default: false,
	},
	appointmentId: {
		type: Number,
		default: null,
	},
	remindingUsers: {
		type: Set,
		default: () => new Set(),
	},
	settingAnswer: {
		type: Set,
		default: () => new Set(),
	},
})

const emit = defineEmits(['remind', 'setAnswer'])

const sortedUsers = computed(() => [...props.users]
	.sort((a, b) => a.displayName.localeCompare(b.displayName)))
</script>

<style scoped lang="scss">
.pending-users {
    margin-top: 10px;

    &__header {
        font-size: 13px;
        color: var(--color-text-maxcontrast);
        margin-bottom: 6px;
    }

    &__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
}

.pending-user {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    // Tighter on the button side so the chip hugs it.
    padding: 3px 4px 3px 10px;
    border-radius: 16px;
    border: 1px solid var(--color-border);
    font-size: 13px;
    white-space: nowrap;

    &--pending {
        opacity: 0.5;
    }
}
</style>
