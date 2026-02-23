<template>
	<div class="streak-badge" :class="'level-' + streakLevel">
		<component :is="levelIcon" :size="20" class="streak-icon" />
		<span class="streak-text">
			{{ t('attendance', '{count} in a row', { count: currentStreak }) }}
		</span>
		<span v-if="showLongest && longestStreak > currentStreak" class="streak-best">
			{{ t('attendance', 'Best: {count}', { count: longestStreak }) }}
		</span>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import FireIcon from 'vue-material-design-icons/Fire.vue'
import TrophyIcon from 'vue-material-design-icons/Trophy.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import CalendarCheckIcon from 'vue-material-design-icons/CalendarCheck.vue'

const props = defineProps({
	currentStreak: {
		type: Number,
		required: true,
	},
	longestStreak: {
		type: Number,
		default: 0,
	},
	streakLevel: {
		type: String,
		default: 'none',
	},
	showLongest: {
		type: Boolean,
		default: true,
	},
})

const levelIcon = computed(() => {
	switch (props.streakLevel) {
	case 'unstoppable':
		return TrophyIcon
	case 'on_fire':
		return FireIcon
	case 'consistent':
		return StarIcon
	default:
		return CalendarCheckIcon
	}
})
</script>

<style scoped lang="scss">
.streak-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px;
	border-radius: var(--border-radius-pill);
	font-size: 0.9em;
}

.streak-text {
	font-weight: 600;
}

.streak-best {
	opacity: 0.7;
	margin-left: 2px;

	&::before {
		content: '·';
		margin-right: 6px;
	}
}

// Starting (1-4): subtle green
.level-starting {
	background: #e8f5e9;
	color: #2e7d32;

	.streak-icon {
		color: #43a047;
	}
}

// Consistent (5-9): blue
.level-consistent {
	background: #e3f2fd;
	color: #1565c0;

	.streak-icon {
		color: #1976d2;
	}
}

// On fire (10-24): orange/red gradient feel
.level-on_fire {
	background: #fff3e0;
	color: #e65100;

	.streak-icon {
		color: #ff6d00;
	}
}

// Unstoppable (25+): gold/amber
.level-unstoppable {
	background: linear-gradient(135deg, #fff8e1, #fff3e0);
	color: #e65100;
	box-shadow: 0 0 0 1px rgba(255, 160, 0, 0.3);

	.streak-icon {
		color: #ffa000;
	}
}

// Dark mode support
:global(body[data-theme-dark]) .level-starting,
:global(body[data-themes*="dark"]) .level-starting {
	background: rgba(46, 125, 50, 0.15);
}

:global(body[data-theme-dark]) .level-consistent,
:global(body[data-themes*="dark"]) .level-consistent {
	background: rgba(25, 118, 210, 0.15);
}

:global(body[data-theme-dark]) .level-on_fire,
:global(body[data-themes*="dark"]) .level-on_fire {
	background: rgba(230, 81, 0, 0.15);
}

:global(body[data-theme-dark]) .level-unstoppable,
:global(body[data-themes*="dark"]) .level-unstoppable {
	background: rgba(255, 160, 0, 0.15);
	box-shadow: 0 0 0 1px rgba(255, 160, 0, 0.2);
}

@media (prefers-color-scheme: dark) {
	:global(body[data-theme-default]) .level-starting {
		background: rgba(46, 125, 50, 0.15);
	}

	:global(body[data-theme-default]) .level-consistent {
		background: rgba(25, 118, 210, 0.15);
	}

	:global(body[data-theme-default]) .level-on_fire {
		background: rgba(230, 81, 0, 0.15);
	}

	:global(body[data-theme-default]) .level-unstoppable {
		background: rgba(255, 160, 0, 0.15);
		box-shadow: 0 0 0 1px rgba(255, 160, 0, 0.2);
	}
}
</style>
