<template>
	<span class="appointment-meta">
		{{ dateHead }} <span class="appointment-meta__tail">{{ dateTail }}<!--
			The badges share a no-wrap span with the date's last token, so a
			narrow screen wraps date text — never a lone icon.
		--><span class="appointment-meta__icons">
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
						:aria-label="organizedByLabel">
						<AccountStarIcon :size="15" />
					</span>
				</template>
				<div class="meta-tooltip">
					<span>{{ organizedByLabel }}</span>
				</div>
			</NcPopover>
		</span></span>
	</span>
</template>

<script setup>
import { NcPopover } from '@nextcloud/vue'
import { computed } from 'vue'
import AccountStarIcon from 'vue-material-design-icons/AccountStar.vue'
import CalendarSyncIcon from 'vue-material-design-icons/CalendarSync.vue'
import RepeatIcon from 'vue-material-design-icons/Repeat.vue'
import { calendarDeepLink } from '../../utils/appointment.js'
import { HOVER_TOOLTIP as TOOLTIP } from '../../utils/tooltip.js'

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

const calendarLink = computed(() => calendarDeepLink(props.appointment))

// Same last-word split as the card title: the final token of the date shares a
// no-wrap span with the icons, so they always keep text company when wrapping.
const dateParts = computed(() => {
	const text = (props.dateText || '').trim()
	const lastSpace = text.lastIndexOf(' ')
	return lastSpace === -1
		? { head: '', tail: text }
		: { head: text.slice(0, lastSpace), tail: text.slice(lastSpace + 1) }
})
const dateHead = computed(() => dateParts.value.head)
const dateTail = computed(() => dateParts.value.tail)

const organizerNames = computed(() => (props.appointment.organizers || [])
	.map((organizer) => organizer.label || organizer.id || organizer)
	.filter((name) => typeof name === 'string' && name)
	.join(', '))

// Both the tooltip and the icon's aria-label read this — a computed is also the
// only place a TRANSLATORS hint binds, an attribute binding never gets one.
const organizedByLabel = computed(() => {
	// TRANSLATORS: Tooltip on the organizer icon of an appointment. {names} is a comma-separated list of the organizers' display names — usually one name, but there can be several.
	return t('attendance', 'Organized by {names}', { names: organizerNames.value })
})
</script>

<style scoped lang="scss">
.appointment-meta {
    font-size: 15px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);

    &__tail {
        white-space: nowrap;
    }

    &__icons {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
        vertical-align: -3px;
        margin-inline-start: 8px;

        &:empty {
            display: none;
        }
    }

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
