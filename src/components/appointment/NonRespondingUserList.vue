<template>
	<div class="reminder-user-list" :class="`reminder-user-list--${variant}`">
		<div class="reminder-user-list__header">
			{{ headerText }}
		</div>
		<div class="reminder-user-list__users">
			<template
				v-for="(u, idx) in sortedUsers"
				:key="u.userId">
				<NcPopover
					v-if="canManageAppointments && appointmentId"
					:shown="openPopover === u.userId"
					popup-role="dialog"
					class="remind-popover-wrapper"
					@update:shown="(val) => openPopover = val ? u.userId : null">
					<template #trigger>
						<span
							class="reminder-user reminder-user--clickable"
							:class="{ 'reminder-user--pending': remindingUsers.has(u.userId) }"
							role="button"
							tabindex="0"
							@keydown.enter.prevent="openPopover = u.userId"
							@keydown.space.prevent="openPopover = u.userId">{{ u.displayName }}<BellRingOutlineIcon :size="14" class="remind-icon" /></span>
					</template>
					<div class="remind-popover" role="dialog" aria-modal="true">
						<p>{{ t('attendance', 'Send a reminder to {name}?', { name: u.displayName }) }}</p>
						<NcButton
							variant="primary"
							:disabled="remindingUsers.has(u.userId)"
							@click="handleRemind(u.userId)">
							<template #icon>
								<BellRingOutlineIcon :size="20" />
							</template>
							{{ t('attendance', 'Send reminder') }}
						</NcButton>
					</div>
				</NcPopover><span
					v-else
					class="reminder-user">{{ u.displayName }}</span><template v-if="idx < sortedUsers.length - 1">, </template>
			</template>
		</div>
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { NcButton, NcPopover } from '@nextcloud/vue'
import BellRingOutlineIcon from 'vue-material-design-icons/BellRingOutline.vue'

const props = defineProps({
	users: {
		type: Array,
		required: true,
	},
	headerText: {
		type: String,
		default: '',
	},
	variant: {
		type: String,
		default: 'default',
		validator: (v) => ['default', 'warning'].includes(v),
	},
	canManageAppointments: {
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
})

const emit = defineEmits(['remind'])

const openPopover = ref(null)

const handleRemind = (userId) => {
	openPopover.value = null
	emit('remind', userId)
}

const sortedUsers = computed(() => {
	if (!props.users || props.users.length === 0) return []
	return [...props.users].sort((a, b) =>
		a.displayName.localeCompare(b.displayName),
	)
})
</script>

<style scoped lang="scss">
.reminder-user-list {
    margin-top: 10px;
    padding: 8px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius);

    &--warning {
        background: var(--color-warning-hover, rgba(250, 200, 0, 0.08));
    }

    .reminder-user-list__header {
        font-weight: 500;
        margin-bottom: 5px;
        font-size: 13px;
        color: var(--color-text-maxcontrast);
    }

    .reminder-user-list__users {
        font-size: 13px;
        color: var(--color-text-lighter);

        .reminder-user {
            white-space: nowrap;
        }

        :deep(.remind-popover-wrapper) {
            display: inline;
        }

        .reminder-user--clickable {
            cursor: pointer;

            &:hover {
                text-decoration: underline;
            }

            &.reminder-user--pending {
                opacity: 0.5;
                cursor: wait;
            }
        }

        .remind-icon {
            display: inline-flex;
            margin-left: 2px;
            opacity: 0.5;
            vertical-align: middle;
            cursor: pointer;
        }
    }
}
</style>

<style lang="scss">
.remind-popover {
    padding: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;

    p {
        margin: 0 0 10px 0;
    }
}
</style>
