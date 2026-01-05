<template>
	<div v-if="responseSummary" class="response-summary-detailed" data-test="response-summary">
		<h4>{{ t('attendance', 'Response Summary') }}</h4>

		<!-- Overall Stats -->
		<div class="summary-stats">
			<NcChip :text="`${t('attendance', 'Yes')}: ${responseSummary.yes}`" variant="success" no-close />
			<NcChip :text="`${t('attendance', 'Maybe')}: ${responseSummary.maybe}`" variant="warning" no-close />
			<NcChip :text="`${t('attendance', 'No')}: ${responseSummary.no}`" variant="error" no-close />
			<NcChip :text="`${t('attendance', 'No Response')}: ${responseSummary.no_response}`" variant="tertiary" no-close />
		</div>

		<!-- Unified Group/Team Summary -->
		<div v-if="hasGroupsOrTeams" class="group-summary" data-test="group-summary">
			<!-- Groups -->
			<div v-for="(groupStats, groupId) in responseSummary.by_group"
				:key="`group-${groupId}`"
				class="group-container"
				:data-test="`group-container-${groupId}`">
				<div class="group-stats clickable" data-test="group-header" @click="toggleGroup(`group-${groupId}`)">
					<div class="group-name">
						<span class="expand-icon" :class="{ expanded: expandedGroups[`group-${groupId}`] }">▶</span>
						<AccountGroup :size="18" class="type-icon" />
						{{ groupId }}
					</div>
					<div class="group-counts">
						<NcChip :text="String(groupStats.yes)" variant="success" no-close />
						<NcChip :text="String(groupStats.maybe)" variant="warning" no-close />
						<NcChip :text="String(groupStats.no)" variant="error" no-close />
						<NcChip :text="String(groupStats.no_response)" variant="tertiary" no-close />
					</div>
				</div>

				<!-- Expanded Group Details -->
				<div v-if="expandedGroups[`group-${groupId}`]" class="group-details">
					<!-- Show responses if any exist -->
					<div v-if="groupStats.responses && groupStats.responses.length > 0" class="group-responses">
						<div v-for="response in getSortedResponses(groupStats.responses)" :key="response.id" class="response-item">
							<div class="response-header">
								<div class="user-info">
									<strong>{{ response.userName }}</strong>
									<NcChip
										:text="getResponseText(response.response)"
										:variant="getResponseVariant(response.response)"
										no-close />
								</div>
								<div v-if="response.isCheckedIn" class="checkin-info">
									<span class="checkin-badge">{{ t('attendance', 'Checked in?') }}</span>
									<NcChip
										:text="getResponseText(response.checkinState)"
										:variant="getResponseVariant(response.checkinState)"
										no-close />
								</div>
							</div>
							<div v-if="canSeeComments && response.comment && response.comment.trim()" class="response-comment">
								{{ response.comment }}
							</div>
						</div>
					</div>

					<!-- Non-responding users -->
					<div v-if="groupStats.non_responding_users && groupStats.non_responding_users.length > 0" class="non-responding-users">
						<div class="non-responding-header">
							{{ t('attendance', 'No response yet:') }}
						</div>
						<div class="non-responding-list">
							{{ getSortedNonRespondingUsers(groupStats.non_responding_users).map(u => u.displayName).join(', ') }}
						</div>
					</div>
				</div>
			</div>

			<!-- Teams -->
			<div v-for="(teamStats, teamId) in responseSummary.by_team"
				:key="`team-${teamId}`"
				class="group-container"
				:data-test="`team-container-${teamId}`">
				<div class="group-stats clickable" data-test="team-header" @click="toggleGroup(`team-${teamId}`)">
					<div class="group-name">
						<span class="expand-icon" :class="{ expanded: expandedGroups[`team-${teamId}`] }">▶</span>
						<AccountStar :size="18" class="type-icon" />
						{{ teamStats.displayName || teamId }}
					</div>
					<div class="group-counts">
						<NcChip :text="String(teamStats.yes)" variant="success" no-close />
						<NcChip :text="String(teamStats.maybe)" variant="warning" no-close />
						<NcChip :text="String(teamStats.no)" variant="error" no-close />
						<NcChip :text="String(teamStats.no_response)" variant="tertiary" no-close />
					</div>
				</div>

				<!-- Expanded Team Details -->
				<div v-if="expandedGroups[`team-${teamId}`]" class="group-details">
					<!-- Show responses if any exist -->
					<div v-if="teamStats.responses && teamStats.responses.length > 0" class="group-responses">
						<div v-for="response in getSortedResponses(teamStats.responses)" :key="response.id" class="response-item">
							<div class="response-header">
								<div class="user-info">
									<strong>{{ response.userName }}</strong>
									<NcChip
										:text="getResponseText(response.response)"
										:variant="getResponseVariant(response.response)"
										no-close />
								</div>
								<div v-if="response.isCheckedIn" class="checkin-info">
									<span class="checkin-badge">{{ t('attendance', 'Checked in?') }}</span>
									<NcChip
										:text="getResponseText(response.checkinState)"
										:variant="getResponseVariant(response.checkinState)"
										no-close />
								</div>
							</div>
							<div v-if="canSeeComments && response.comment && response.comment.trim()" class="response-comment">
								{{ response.comment }}
							</div>
						</div>
					</div>

					<!-- Non-responding users -->
					<div v-if="teamStats.non_responding_users && teamStats.non_responding_users.length > 0" class="non-responding-users">
						<div class="non-responding-header">
							{{ t('attendance', 'No response yet:') }}
						</div>
						<div class="non-responding-list">
							{{ getSortedNonRespondingUsers(teamStats.non_responding_users).map(u => u.displayName).join(', ') }}
						</div>
					</div>
				</div>
			</div>

			<!-- Others Section -->
			<div v-if="responseSummary.others && hasOthersResponses()" class="group-container">
				<div class="group-stats clickable" @click="toggleGroup('others')">
					<div class="group-name">
						<span class="expand-icon" :class="{ expanded: expandedGroups['others'] }">▶</span>
						{{ t('attendance', 'Others') }}
					</div>
					<div class="group-counts">
						<NcChip :text="String(responseSummary.others.yes)" variant="success" no-close />
						<NcChip :text="String(responseSummary.others.maybe)" variant="warning" no-close />
						<NcChip :text="String(responseSummary.others.no)" variant="error" no-close />
					</div>
				</div>

				<!-- Expanded Others Details -->
				<div v-if="expandedGroups['others']" class="group-details">
					<div v-if="responseSummary.others.responses.length > 0" class="group-responses">
						<div v-for="response in getSortedResponses(responseSummary.others.responses)" :key="response.id" class="response-item">
							<div class="response-header">
								<div class="user-info">
									<strong>{{ response.userName }}</strong>
									<NcChip
										:text="getResponseText(response.response)"
										:variant="getResponseVariant(response.response)"
										no-close />
								</div>
								<div v-if="response.isCheckedIn" class="checkin-info">
									<span class="checkin-badge">{{ t('attendance', 'Checked in?') }}</span>
									<NcChip
										:text="getResponseText(response.checkinState)"
										:variant="getResponseVariant(response.checkinState)"
										no-close />
								</div>
							</div>
							<div v-if="canSeeComments && response.comment && response.comment.trim()" class="response-comment">
								{{ response.comment }}
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { NcChip } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountStar from 'vue-material-design-icons/AccountStar.vue'
import { getResponseText, getResponseVariant } from '../../utils/response.js'

const props = defineProps({
	responseSummary: {
		type: Object,
		default: null,
	},
	canSeeComments: {
		type: Boolean,
		default: true,
	},
})

const expandedGroups = ref({})

const hasGroupsOrTeams = computed(() => {
	if (!props.responseSummary) return false
	const hasGroups = props.responseSummary.by_group && Object.keys(props.responseSummary.by_group).length > 0
	const hasTeams = props.responseSummary.by_team && Object.keys(props.responseSummary.by_team).length > 0
	return hasGroups || hasTeams
})

const toggleGroup = (groupId) => {
	expandedGroups.value[groupId] = !expandedGroups.value[groupId]
}

const getSortedResponses = (responses) => {
	if (!responses || responses.length === 0) return []
	return [...responses].sort((a, b) => a.userName.localeCompare(b.userName))
}

const getSortedNonRespondingUsers = (users) => {
	if (!users || users.length === 0) return []
	return [...users].sort((a, b) => a.displayName.localeCompare(b.displayName))
}

const hasOthersResponses = () => {
	if (!props.responseSummary?.others) return false
	return props.responseSummary.others.yes > 0
		|| props.responseSummary.others.maybe > 0
		|| props.responseSummary.others.no > 0
}
</script>

<style scoped lang="scss">
.response-summary-detailed {
	border-top: 1px solid var(--color-border);
	padding-top: 15px;
	margin-top: 15px;

	h4 {
		margin: 0 0 10px 0;
	}

	h5 {
		margin: 15px 0 10px 0;
		font-size: 14px;
		color: var(--color-text-lighter);
	}

	.summary-stats {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
		margin-bottom: 15px;
	}
}

.group-summary {
	.group-container {
		margin-bottom: 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius);
		overflow: hidden;
	}

	.group-stats {
		display: flex;
		justify-content: space-between;
		align-items: center;
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
		padding: 10px;
		background: var(--color-main-background);
	}

	.group-responses {
		.response-item {
			padding: 8px;
			margin-bottom: 8px;
			border-left: 3px solid var(--color-border);
			background: var(--color-background-hover);
			border-radius: 0 var(--border-radius) var(--border-radius) 0;

			&:last-child {
				margin-bottom: 0;
			}

			.response-header {
				display: flex;
				justify-content: space-between;
				align-items: center;

				.user-info {
					display: flex;
					align-items: center;
					gap: 8px;

					strong {
						font-size: 14px;
					}
				}

				.checkin-info {
					display: flex;
					align-items: center;
					gap: 5px;
					font-size: 13px;

					.checkin-badge {
						color: var(--color-text-lighter);
					}
				}
			}

			.response-comment {
				font-size: 13px;
				color: var(--color-text-lighter);
				font-style: italic;
				padding-top: 5px;
			}
		}
	}

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
		}
	}
}
</style>
