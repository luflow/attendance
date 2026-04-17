<template>
	<div class="non-responding-users">
		<div class="non-responding-header">
			{{ t("attendance", "No response yet:") }}
		</div>
		<div class="non-responding-list">
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
							class="non-responding-user non-responding-user--clickable"
							:class="{ 'non-responding-user--pending': remindingUsers.has(u.userId) }"
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
					class="non-responding-user">{{ u.displayName }}</span><template v-if="idx < sortedUsers.length - 1">, </template>
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
.non-responding-users {
    margin-top: 10px;
    padding: 8px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius);

    .non-responding-header {
        font-weight: 500;
        margin-bottom: 5px;
        font-size: 13px;
        color: var(--color-text-maxcontrast);
    }

    .non-responding-list {
        font-size: 13px;
        color: var(--color-text-lighter);

        .non-responding-user {
            white-space: nowrap;
        }

        :deep(.remind-popover-wrapper) {
            display: inline;
        }

        .non-responding-user--clickable {
            cursor: pointer;

            &:hover {
                text-decoration: underline;
            }

            &.non-responding-user--pending {
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
