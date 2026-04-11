<template>
	<div class="non-responding-users">
		<div class="non-responding-header">
			{{ t("attendance", "No response yet:") }}
		</div>
		<div class="non-responding-list">
			<span
				v-for="(u, idx) in sortedUsers"
				:key="u.userId"
				class="non-responding-user">{{ u.displayName }}<NcPopover
					v-if="canManageAppointments && appointmentId"
					:shown="openPopover === u.userId"
					popup-role="dialog"
					class="remind-popover-wrapper"
					@update:shown="(val) => openPopover = val ? u.userId : null">
					<template #trigger>
						<button
							class="remind-btn"
							:disabled="remindingUsers.has(u.userId)">
							<BellRingOutlineIcon :size="14" />
						</button>
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
				</NcPopover><template v-if="idx < sortedUsers.length - 1">, </template>
			</span>
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

        .remind-popover-wrapper {
            display: inline;
        }

        .remind-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            padding: 0 2px;
            margin: 0;
            cursor: pointer;
            color: var(--color-text-maxcontrast);
            opacity: 0.5;
            transition: opacity 0.2s, color 0.2s;
            vertical-align: middle;

            &:hover:not(:disabled) {
                opacity: 1;
                color: var(--color-primary-element);
            }

            &:disabled {
                opacity: 0.3;
                cursor: wait;
            }
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
