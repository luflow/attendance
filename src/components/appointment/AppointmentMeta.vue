<template>
	<span class="appointment-meta">
		<span class="appointment-meta__date">{{ dateText }}</span>

		<NcPopover
			v-if="calendarLink"
			v-bind="TOOLTIP"
			class="appointment-meta__popover">
			<template #trigger>
				<span
					class="appointment-meta__icon calendar-link"
					tabindex="0"
					role="img"
					:aria-label="t('attendance', 'Imported from calendar')">
					<CalendarSyncIcon :size="15" />
				</span>
			</template>
			<div class="meta-tooltip">
				<span>{{ t('attendance', 'Imported from the Nextcloud calendar.') }}</span>
				<a :href="calendarLink" target="_blank" rel="noopener noreferrer">
					{{ t('attendance', 'Open in calendar') }} →
				</a>
			</div>
		</NcPopover>

		<NcPopover
			v-if="appointment.seriesId"
			v-bind="TOOLTIP"
			class="appointment-meta__popover">
			<template #trigger>
				<span
					class="appointment-meta__icon series-indicator"
					tabindex="0"
					role="img"
					:aria-label="t('attendance', 'Part of a recurring series')">
					<RepeatIcon :size="15" />
				</span>
			</template>
			<div class="meta-tooltip">
				<span>{{ t('attendance', 'Part of a series — this appointment repeats regularly.') }}</span>
			</div>
		</NcPopover>

		<NcPopover
			v-if="organizerNames"
			v-bind="TOOLTIP"
			class="appointment-meta__popover">
			<template #trigger>
				<span
					class="appointment-meta__icon"
					tabindex="0"
					role="img"
					data-test="organizer-info"
					:aria-label="t('attendance', 'Organized by {names}', { names: organizerNames })">
					<AccountStarIcon :size="15" />
				</span>
			</template>
			<div class="meta-tooltip">
				<span>{{ t('attendance', 'Organized by {names}', { names: organizerNames }) }}</span>
			</div>
		</NcPopover>
	</span>
</template>

<script setup>
import { NcPopover } from '@nextcloud/vue'
import { computed } from 'vue'
import AccountStarIcon from 'vue-material-design-icons/AccountStar.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import { calendarDeepLink } from '../../utils/appointment.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
	dateText: {
		type: String,
		default: '',
	},
})

// popperTriggers keeps the tooltip open while the pointer is over it — without
// it the calendar tooltip closes the moment you move towards its link, so the
// link is unreachable. The hide delay bridges the gap between icon and popper.
const TOOLTIP = {
	triggers: ['hover', 'focus'],
	popperTriggers: ['hover'],
	delay: { show: 100, hide: 300 },
	popupRole: 'tooltip',
}

const calendarLink = computed(() => calendarDeepLink(props.appointment))

const organizerNames = computed(() => (props.appointment.organizers || [])
	.map((organizer) => organizer.label || organizer.id || organizer)
	.filter((name) => typeof name === 'string' && name)
	.join(', '))
</script>

<style scoped lang="scss">
.appointment-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 15px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);

    &__popover {
        display: inline-flex;
    }

    &__icon {
        display: inline-flex;
        align-items: center;
        color: var(--color-text-maxcontrast);
        cursor: default;

        &:hover,
        &:focus-visible {
            color: var(--color-main-text);
        }
    }
}
</style>

<style lang="scss">
// Rendered into the popover teleport target, so it cannot be scoped.
.meta-tooltip {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 8px 10px;
    max-width: 240px;
    font-size: 13px;
    line-height: 1.4;

    a {
        color: var(--color-primary-element);
        font-weight: 600;
    }
}
</style>
