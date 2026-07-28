<template>
	<div class="list-card" data-test="appointment-card">
		<div class="list-card__header" :class="{ 'list-card__header--cancelled': isCancelled }">
			<a
				:href="detailUrl"
				class="list-card__headline"
				data-test="appointment-title-link"
				@click.prevent="emit('openDetail', appointment.id)">
				<div class="list-card__headline-row">
					<h3 data-test="appointment-title" :class="{ 'title-cancelled': isCancelled }">
						{{ titleText }}
					</h3>
					<ChevronRightIcon :size="20" class="list-card__chevron" />
					<AppointmentStatusChips :appointment="appointment" />
				</div>
				<AppointmentMeta :appointment="appointment" :dateText="subtitleText" />
			</a>
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
			</ResponseEditor>
			<div v-else class="list-card__response" data-test="response-section-readonly">
				<span class="list-card__label">{{ t("attendance", "Your response") }}</span>
				<span v-if="userResponse" class="list-card__answer">
					<ResponseDot :response="userResponse" />
					<strong>{{ getResponseText(userResponse) }}</strong>
				</span>
				<span v-else class="list-card__answer">{{ t("attendance", "No response") }}</span>
				<span class="list-card__note" data-test="closed-info">· {{ stateLabel }}</span>
			</div>

			<ResponseBar v-if="canSeeResponses && appointment.responseSummary" :segments="summarySegments">
				<template #trailing>
					<a
						:href="detailUrl"
						class="list-card__details"
						data-test="button-show-details"
						@click.prevent="emit('openDetail', appointment.id)">
						{{ t("attendance", "Show details") }}
						<ChevronRightIcon :size="15" />
					</a>
				</template>
			</ResponseBar>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import AppointmentActionsMenu from './AppointmentActionsMenu.vue'
import AppointmentMeta from './AppointmentMeta.vue'
import AppointmentStatusChips from './AppointmentStatusChips.vue'
import ResponseBar from './ResponseBar.vue'
import ResponseDot from './ResponseDot.vue'
import ResponseEditor from './ResponseEditor.vue'
import { useAppointmentCard } from '../../composables/useAppointmentCard.js'
import { appointmentDetailUrl, formatCancelledLabel, formatClosedLabel } from '../../utils/appointment.js'
import { stripMarkdown } from '../../utils/markdown.js'
import { getResponseText, responseSegments } from '../../utils/response.js'

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
	canSeeResponses,
	canSeeAuditLog,
	titleText,
	subtitleText,
} = useAppointmentCard(() => props.appointment)

const detailUrl = computed(() => appointmentDetailUrl(props.appointment.id))

// One line of plain text — the full markdown lives on the detail page. The
// slice keeps the ~20 regex passes of stripMarkdown() off long descriptions;
// nothing beyond the first line is ever shown.
const descriptionPreview = computed(() => stripMarkdown((props.appointment.description || '').slice(0, 300)))

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

    &__headline {
        flex: 1;
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
        }
    }

    &__chevron {
        display: inline-flex;
        margin-left: -4px;
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
            flex: 1;
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

    &__details {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 13.5px;
        font-weight: 600;
        color: var(--color-primary-element);
        text-decoration: none;

        &:hover {
            text-decoration: underline;
        }
    }
}
</style>
