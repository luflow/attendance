<template>
	<div class="streak-leaders-widget">
		<NcDashboardWidget
			:items="items"
			:loading="false">
			<template #empty-content>
				<NcEmptyContent :title="t('attendance', 'No streaks yet')">
					<template #icon>
						<FireIcon />
					</template>
				</NcEmptyContent>
			</template>
			<template #default="{ item }">
				<div class="streak-leader-item">
					<NcAvatar :user="item.id" :size="32" :show-user-status="false" />
					<div class="streak-leader-info">
						<span class="streak-leader-name">{{ item.mainText }}</span>
						<span class="streak-leader-count" :class="'level-' + item.level">
							<component :is="getLevelIcon(item.level)" :size="16" />
							{{ t('attendance', '{count} in a row', { count: item.streak }) }}
						</span>
					</div>
				</div>
			</template>
		</NcDashboardWidget>

		<div class="widget-footer">
			<NcButton
				variant="primary"
				wide
				@click="goToAttendanceApp">
				{{ t('attendance', 'Show all appointments') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { NcDashboardWidget, NcEmptyContent, NcButton, NcAvatar } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import FireIcon from 'vue-material-design-icons/Fire.vue'
import TrophyIcon from 'vue-material-design-icons/Trophy.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import CalendarCheckIcon from 'vue-material-design-icons/CalendarCheck.vue'

defineProps({
	title: {
		type: String,
		required: true,
	},
})

let leaders = []
try {
	leaders = loadState('attendance', 'streak-leaders-items')
} catch (error) {
	console.error('Error loading streak leaders:', error)
}

const items = computed(() => {
	return leaders.map((leader) => ({
		id: leader.userId,
		mainText: leader.displayName,
		streak: leader.currentStreak,
		level: leader.level,
	}))
})

const getLevelIcon = (level) => {
	switch (level) {
	case 'unstoppable':
		return TrophyIcon
	case 'on_fire':
		return FireIcon
	case 'consistent':
		return StarIcon
	default:
		return CalendarCheckIcon
	}
}

const goToAttendanceApp = () => {
	window.location.href = generateUrl('/apps/attendance/')
}
</script>

<style scoped lang="scss">
.streak-leaders-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
	max-height: 450px;
}

.streak-leader-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	width: 100%;
}

.streak-leader-info {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.streak-leader-name {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.streak-leader-count {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);

	&.level-starting {
		color: var(--color-text-maxcontrast);
	}

	&.level-consistent {
		color: #1976d2;
	}

	&.level-on_fire {
		color: #ff6d00;
	}

	&.level-unstoppable {
		color: #ffd600;
	}
}

.widget-footer {
	flex-shrink: 0;
	padding: 12px 12px 20px 12px;
}
</style>
