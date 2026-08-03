<template>
	<div
		v-if="responseSummary"
		class="response-summary-detailed"
		data-test="response-summary">
		<h4>{{ t("attendance", "Response summary") }}</h4>

		<ResponseBar class="response-summary-detailed__bar" :segments="responseSegments(responseSummary)" />

		<div v-if="sections.length" class="group-summary" data-test="group-summary">
			<div
				v-for="section in sections"
				:key="section.key"
				class="group-container"
				:data-test="section.containerTest">
				<div
					class="group-stats clickable"
					:data-test="section.headerTest"
					@click="toggleGroup(section.key)">
					<div class="group-name">
						<span class="expand-icon" :class="{ expanded: expandedGroups[section.key] }">▶</span>
						<component :is="section.icon"
							v-if="section.icon"
							:size="18"
							class="type-icon" />
						{{ section.label }}
					</div>
					<div class="group-counts">
						<NcChip
							v-for="segment in responseSegments(section.stats)"
							:key="segment.key"
							:text="String(segment.count)"
							:variant="segment.variant"
							noClose />
					</div>
				</div>

				<div v-if="expandedGroups[section.key]" class="group-details">
					<div v-if="section.stats.responses?.length" class="group-responses">
						<ResponseRow
							v-for="response in sortByName(section.stats.responses)"
							:key="response.id"
							:response="response"
							:canSeeComments="canSeeComments"
							:canSendReminders="canSendReminders"
							:canManageBooking="canManageBooking"
							:isClosed="isClosed"
							:canSetAnswer="canSetAnswer"
							:remindingUsers="remindingUsers"
							:togglingBooking="togglingBooking"
							:settingAnswer="settingAnswer"
							@remind="remindUser"
							@toggleBooking="toggleBooking"
							@setAnswer="setAnswer" />
					</div>

					<NonRespondingUserList
						v-if="section.stats.non_responding_users?.length"
						:users="section.stats.non_responding_users"
						:headerText="t('attendance', 'No response yet:')"
						:canManageAppointments="canSendReminders"
						:canSetAnswer="canSetAnswer"
						:appointmentId="appointmentId"
						:remindingUsers="remindingUsers"
						:settingAnswer="settingAnswer"
						@remind="remindUser"
						@setAnswer="setAnswer" />
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcChip } from '@nextcloud/vue'
import { computed, reactive, ref } from 'vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import NonRespondingUserList from './NonRespondingUserList.vue'
import ResponseBar from './ResponseBar.vue'
import ResponseRow from './ResponseRow.vue'
import { usePermissions } from '../../composables/usePermissions.js'
import { formatGroupLabel } from '../../utils/groups.js'
import { responseSegments } from '../../utils/response.js'

const props = defineProps({
	responseSummary: {
		type: Object,
		default: null,
	},
	canSeeComments: {
		type: Boolean,
		default: false,
	},
	canManageAppointments: {
		type: Boolean,
		default: false,
	},
	appointmentId: {
		type: Number,
		default: null,
	},
	isClosed: {
		type: Boolean,
		default: false,
	},
	// Whether the appointment still takes responses (open and not cancelled) —
	// the on-behalf answer editor follows the same rule as self-responses.
	acceptsResponses: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['refreshAppointment'])

const { capabilities, permissions } = usePermissions()

const canSendReminders = computed(() => props.canManageAppointments && !props.isClosed)

// The per-person booking toggle only shows when the feature is on and the
// viewer may manage the appointment; individual rows additionally require a
// "yes" response (handled by the row).
const canManageBooking = computed(() => props.canManageAppointments && capabilities.bookingEnabled)

// Recording an answer on a person's behalf (issue #47) is its own admin-
// configured permission — deliberately not implied by manage rights — and
// only while the inquiry still accepts responses.
const canSetAnswer = computed(() => permissions.canRespondForOthers && props.acceptsResponses)

const expandedGroups = ref({})
const remindingUsers = reactive(new Set())
const togglingBooking = reactive(new Set())
const settingAnswer = reactive(new Set())

// Groups, teams and the catch-all "Others" bucket all render the same way — the
// only differences are the label, the leading icon and the data-test hooks.
const sections = computed(() => {
	const summary = props.responseSummary
	if (!summary) return []
	const result = []
	for (const [groupId, stats] of Object.entries(summary.by_group ?? {})) {
		result.push({
			key: `group-${groupId}`,
			label: formatGroupLabel(groupId),
			icon: AccountGroup,
			containerTest: `group-container-${groupId}`,
			headerTest: 'group-header',
			stats,
		})
	}
	for (const [teamId, stats] of Object.entries(summary.by_team ?? {})) {
		result.push({
			key: `team-${teamId}`,
			label: stats.displayName || teamId,
			icon: AccountStar,
			containerTest: `team-container-${teamId}`,
			headerTest: 'team-header',
			stats,
		})
	}
	const others = summary.others
	const othersHasContent = others
		&& (others.yes > 0 || others.maybe > 0 || others.no > 0 || others.non_responding_users?.length > 0)
	if (othersHasContent) {
		result.push({
			key: 'others',
			// TRANSLATORS: Section heading in the response summary for users who are not in any of the tracked groups ("other people").
			label: t('attendance', 'Others'),
			icon: null,
			containerTest: 'others-container',
			headerTest: 'others-header',
			stats: others,
		})
	}
	return result
})

function toggleGroup(key) {
	expandedGroups.value[key] = !expandedGroups.value[key]
}

function sortByName(responses) {
	return [...responses].sort((a, b) => a.userName.localeCompare(b.userName))
}

async function toggleBooking(response) {
	if (!props.appointmentId || togglingBooking.has(response.userId)) return
	const wantBook = response.bookingStatus !== 'booked'
	togglingBooking.add(response.userId)
	try {
		const action = wantBook ? 'book' : 'unbook'
		const { data } = await axios.post(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}/${action}/${encodeURIComponent(response.userId)}`))
		// Reflect the new state on the row in place (the summary object is shared
		// with the parent, so the toggle updates without a refetch).
		response.bookingStatus = data.bookingStatus ?? null
	} catch (error) {
		console.error('Failed to update booking:', error)
		// TRANSLATORS: Error toast when toggling whether a person is scheduled in for the appointment fails (German: "Planung").
		showError(t('attendance', 'Failed to update scheduling'))
	} finally {
		togglingBooking.delete(response.userId)
	}
}

async function setAnswer(userId, response) {
	if (!props.appointmentId || settingAnswer.has(userId)) return
	settingAnswer.add(userId)
	try {
		await axios.post(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}/respond/${encodeURIComponent(userId)}`), { response })
		showSuccess(t('attendance', 'Response updated'))
		// Group buckets, counts and the non-responder list all shift, so let
		// the parent refetch instead of patching the summary in place.
		emit('refreshAppointment')
	} catch (error) {
		console.error('Failed to set answer:', error)
		showError(t('attendance', 'Failed to update response'))
	} finally {
		settingAnswer.delete(userId)
	}
}

async function remindUser(userId) {
	if (!props.appointmentId) return
	remindingUsers.add(userId)
	try {
		await axios.post(generateUrl(`/apps/attendance/api/appointments/${props.appointmentId}/remind/${userId}`))
		showSuccess(t('attendance', 'Reminder sent'))
	} catch (error) {
		console.error('Failed to send reminder:', error)
		showError(t('attendance', 'Failed to send reminder'))
	} finally {
		remindingUsers.delete(userId)
	}
}
</script>

<style scoped lang="scss">
.response-summary-detailed {
    border-top: 1px solid var(--color-border);
    padding-top: 15px;
    margin-top: 15px;

    h4 {
        font-size: 1.2em;
        margin: 0 0 10px 0;
    }

    &__bar {
        margin-bottom: 15px;
    }
}

.group-summary {
    .group-container {
        margin-bottom: 10px;
        border: 1px solid var(--color-border);
        border-radius: var(--border-radius);
        overflow: hidden;

        &:last-child {
            margin-bottom: 0;
        }
    }

    .group-stats {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 10px;
        background: var(--color-background-hover);

        &.clickable {
            cursor: pointer;

            &:hover {
                background: var(--color-background-dark);
            }
        }

        .group-name {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;

            .expand-icon {
                transition: transform 0.2s;
                font-size: 12px;

                &.expanded {
                    transform: rotate(90deg);
                }
            }

            .type-icon {
                color: var(--color-text-maxcontrast);
            }
        }

        .group-counts {
            display: flex;
            gap: 6px;

            :deep(.nc-chip) {
                min-width: 35px;
                text-align: center;
            }
        }
    }

    .group-details {
        padding: 4px 10px 10px;
        background: var(--color-main-background);
    }
}
</style>
