<template>
	<div class="list-card" data-test="appointment-card">
		<div class="list-card__header" :class="{ 'list-card__header--cancelled': isCancelled }">
			<div class="list-card__headline-wrap">
				<a
					:href="detailUrl"
					class="list-card__headline"
					data-test="appointment-title-link"
					@click.prevent="emit('openDetail', appointment.id)">
					<div class="list-card__headline-row">
						<!-- The chevron rides inside the h3, glued to the last word,
						     so it can never wrap onto a line of its own. -->
						<h3 data-test="appointment-title" :class="{ 'title-cancelled': isCancelled }">
							{{ titleHead }} <span class="list-card__title-tail">{{ titleTail }}<ChevronRightIcon :size="20" class="list-card__chevron" /></span>
						</h3>
						<AppointmentStatusChips :appointment="appointment" />
					</div>
					<AppointmentMeta :appointment="appointment" :dateText="subtitleText" />
				</a>
				<a
					v-if="appointment.location"
					:href="osmLocationUrl"
					target="_blank"
					rel="noopener noreferrer"
					class="list-card__location"
					data-test="appointment-location">
					<MapMarkerIcon :size="15" />
					<span class="list-card__location-text">{{ appointment.location }}</span>
				</a>
				<NcPopover
					v-if="categoryName"
					v-bind="HOVER_TOOLTIP"
					class="list-card__category">
					<template #trigger>
						<span
							class="list-card__category-trigger"
							tabindex="0"
							role="img"
							data-test="appointment-category"
							:aria-label="categoryName">
							<component :is="categoryIconComponent(categoryIcon)" :size="15" />
						</span>
					</template>
					<div class="meta-tooltip">
						<span>{{ categoryName }}</span>
					</div>
				</NcPopover>
			</div>
			<AppointmentActionsMenu
				:appointment="appointment"
				:canSeeAuditLog="canSeeAuditLog"
				@startCheckin="emit('startCheckin', $event)"
				@edit="emit('edit', $event)"
				@copy="emit('copy', $event)"
				@delete="emit('delete', $event)"
				@export="emit('export', $event)"
				@showAuditLog="emit('showAuditLog', $event)"
				@closedToggled="emit('closedToggled', $event)" />
		</div>

		<div class="list-card__body">
			<div
				v-if="descriptionPreview || attachmentCount"
				class="list-card__preview"
				role="button"
				tabindex="0"
				:title="t('attendance', 'Show details')"
				@click="emit('openDetail', appointment.id)"
				@keydown.enter="emit('openDetail', appointment.id)">
				<p v-if="descriptionPreview">
					{{ descriptionPreview }}
				</p>
				<span v-if="attachmentCount" class="list-card__attachments" data-test="attachment-chips">
					{{ n("attendance", "+%n attachment", "+%n attachments", attachmentCount) }}
				</span>
			</div>

			<ResponseEditor
				v-if="acceptsResponses"
				compact
				data-test="response-section"
				:appointmentId="appointment.id"
				:userResponse="userResponse"
				:comment="appointment.userResponse?.comment || ''"
				:responseDeadline="appointment.responseDeadline"
				@submitResponse="(id, response) => emit('submitResponse', id, response)">
				<template #label>
					<span class="list-card__label">{{ t("attendance", "Your response") }}</span>
				</template>
				<!-- Without a bar the link trails the answer row instead, so the
				     card never ends on a line of its own. -->
				<template v-if="!detailLinkInBar" #trailing>
					<ShowDetailsLink :appointmentId="appointment.id" @open="emit('openDetail', $event)" />
				</template>
			</ResponseEditor>
			<div v-else class="list-card__response" data-test="response-section-readonly">
				<span class="list-card__label">{{ t("attendance", "Your response") }}</span>
				<span v-if="userResponse" class="list-card__answer">
					<ResponseDot :response="userResponse" />
					<strong>{{ getResponseText(userResponse) }}</strong>
				</span>
				<span v-else class="list-card__answer">{{ t("attendance", "No response") }}</span>
				<span class="list-card__note" data-test="closed-info">· {{ stateLabel }}</span>
				<ShowDetailsLink v-if="!detailLinkInBar"
					:appointmentId="appointment.id"
					@open="emit('openDetail', $event)" />
			</div>

			<!-- The link rides the bar's legend when there is one, but it is not
			     tied to it: the detail page carries description and attachments,
			     which everyone who sees the appointment may read. -->
			<ResponseBar v-if="detailLinkInBar" :segments="summarySegments">
				<template #trailing>
					<ShowDetailsLink :appointmentId="appointment.id" @open="emit('openDetail', $event)" />
				</template>
			</ResponseBar>
		</div>
	</div>
</template>

<script setup>
import { NcPopover } from '@nextcloud/vue'
import { computed } from 'vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarkerOutline.vue'
import AppointmentActionsMenu from './AppointmentActionsMenu.vue'
import AppointmentMeta from './AppointmentMeta.vue'
import AppointmentStatusChips from './AppointmentStatusChips.vue'
import ResponseBar from './ResponseBar.vue'
import ResponseDot from './ResponseDot.vue'
import ResponseEditor from './ResponseEditor.vue'
import ShowDetailsLink from './ShowDetailsLink.vue'
import { useAppointmentCard } from '../../composables/useAppointmentCard.js'
import { appointmentDetailUrl, formatCancelledLabel, formatClosedLabel } from '../../utils/appointment.js'
import { categoryIconComponent } from '../../utils/categoryIcons.js'
import { stripMarkdown } from '../../utils/markdown.js'
import { getResponseText, responseSegments } from '../../utils/response.js'
import { HOVER_TOOLTIP } from '../../utils/tooltip.js'

const props = defineProps({
	appointment: {
		type: Object,
		required: true,
	},
})

const emit = defineEmits([
	'openDetail',
	'startCheckin',
	'edit',
	'copy',
	'delete',
	'export',
	'submitResponse',
	'closedToggled',
	'showAuditLog',
])

const {
	isCancelled,
	acceptsResponses,
	userResponse,
	canSeeAuditLog,
	titleText,
	subtitleText,
	osmLocationUrl,
	categoryName,
	categoryIcon,
} = useAppointmentCard(() => props.appointment)

const detailUrl = computed(() => appointmentDetailUrl(props.appointment.id))

// Split the title before its last word so that word and the chevron can share
// a no-wrap span — the arrow always keeps at least one word next to it.
const titleParts = computed(() => {
	const text = (titleText.value || '').trim()
	const lastSpace = text.lastIndexOf(' ')
	return lastSpace === -1
		? { head: '', tail: text }
		: { head: text.slice(0, lastSpace), tail: text.slice(lastSpace + 1) }
})
const titleHead = computed(() => titleParts.value.head)
const titleTail = computed(() => titleParts.value.tail)

// One line of plain text — the full markdown lives on the detail page. The
// slice keeps the ~20 regex passes of stripMarkdown() off long descriptions;
// nothing beyond the first line is ever shown.
const descriptionPreview = computed(() => stripMarkdown((props.appointment.description || '').slice(0, 300)))

// The link rides the last row there is, so the three placements are one
// decision: it goes into the bar when a bar exists, into the answer row
// otherwise. Exactly one of them renders.
const detailLinkInBar = computed(() => Boolean(props.appointment.responseSummary))

const summarySegments = computed(() => responseSegments(props.appointment.responseSummary))

const attachmentCount = computed(() => props.appointment.attachments?.length ?? 0)

const stateLabel = computed(() => (isCancelled.value
	? formatCancelledLabel(props.appointment.cancelledAt)
	: formatClosedLabel(props.appointment.closedAt, props.appointment.responseDeadline)))
</script>

<style scoped lang="scss">
@use "../../styles/shared.scss";

.list-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    margin-bottom: 12px;

    &__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        padding: 15px 16px 15px 20px;
        background: var(--color-background-hover);
        border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;

        &--cancelled {
            background: rgba(var(--color-error-rgb), 0.12);
        }
    }

    &__headline-wrap {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    &__headline {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 3px;
        color: inherit;
        text-decoration: none;

        &:hover .list-card__chevron,
        &:focus-visible .list-card__chevron {
            transform: translateX(2px);
        }

        .list-card__headline-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            // The chips sit next to the heading, not inside it, so the row sets
            // the size they inherit and the h3 overrides it for itself.
            font-size: 13px;
        }

        h3 {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            color: var(--color-main-text);
            text-wrap: pretty;
            min-width: 0;
        }
    }

    &__title-tail {
        white-space: nowrap;
    }

    &__location {
        display: flex;
        align-items: center;
        gap: 4px;
        min-width: 0;
        font-size: 15px;
        color: var(--color-text-maxcontrast);

        &:hover,
        &:focus-visible {
            color: var(--color-main-text);
            text-decoration: underline;
        }
    }

    &__location-text {
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    &__category {
        display: inline-flex;
        align-self: flex-start;
    }

    &__category-trigger {
        display: inline-flex;
        align-items: center;
        color: var(--color-text-maxcontrast);
        cursor: default;

        &:hover,
        &:focus-visible {
            color: var(--color-main-text);
        }
    }

    &__chevron {
        display: inline-flex;
        vertical-align: -4px;
        margin-inline-start: 2px;
        color: var(--color-text-maxcontrast);
        transition: transform 0.15s ease;
    }

    &__body {
        display: flex;
        flex-direction: column;
        gap: 14px;
        padding: 16px 20px 14px;
    }

    &__preview {
        display: flex;
        align-items: baseline;
        gap: 8px;
        min-width: 0;
        cursor: pointer;
        color: var(--color-text-maxcontrast);

        // Nextcloud's server styles set an explicit cursor on <p>, which beats
        // the inherited pointer from the clickable row.
        > * {
            cursor: inherit;
        }

        p {
            margin: 0;
            // Shrink-to-fit rather than flex: 1 — a short description keeps the
            // attachment count right behind it instead of at the card edge, and
            // a long one still truncates, putting it right after the ellipsis.
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        &:hover {
            color: var(--color-main-text);
        }
    }

    &__attachments {
        flex-shrink: 0;
        font-size: 13.5px;
        white-space: nowrap;
    }

    &__response {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    &__label {
        font-size: 14px;
        font-weight: 600;
        color: var(--color-text-maxcontrast);
    }

    &__answer {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    &__note {
        font-size: 13px;
        color: var(--color-text-maxcontrast);
    }

}
</style>
