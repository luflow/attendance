<template>
	<div class="appointment-item" data-test="widget-appointment-item">
		<!-- Check-in Section (for admins when appointment is ready for check-in) -->
		<div v-if="showCheckinButton" class="checkin-section">
			<NcButton
				variant="primary"
				class="checkin-button"
				data-test="button-widget-checkin"
				@click="$emit('openCheckin', item.id)">
				<template #icon>
					<ListStatusIcon />
				</template>
				{{ t("attendance", "Start check-in") }}
			</NcButton>
		</div>

		<div class="appointment-header" :class="{ 'date-first': displayOrder === 'date_first' }">
			<template v-if="displayOrder === 'date_first'">
				<h3
					class="clickable"
					data-test="widget-appointment-title"
					@click="$emit('openDetail', item.id)">
					{{ formattedDate }}
				</h3>
				<span class="appointment-name">{{ item.mainText }}</span>
			</template>
			<template v-else>
				<h3
					class="clickable"
					data-test="widget-appointment-title"
					@click="$emit('openDetail', item.id)">
					{{ item.mainText }}
				</h3>
				<span class="appointment-time">{{ formattedDate }}</span>
			</template>
		</div>

		<ResponseEditor
			compact
			class="response-section"
			:appointmentId="item.id"
			:userResponse="item.userResponse?.response ?? null"
			:comment="item.userResponse?.comment || ''"
			:responseOptions="responseOptions"
			@submitResponse="(id, response) => emit('respond', id, response)" />
	</div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import { computed } from 'vue'
import ListStatusIcon from 'vue-material-design-icons/ListStatus.vue'
import ResponseEditor from '../appointment/ResponseEditor.vue'
import { formatDateTime } from '../../utils/datetime.js'
import { responseOptionsFor } from '../../utils/response.js'

const props = defineProps({
	item: {
		type: Object,
		required: true,
	},
	showCheckinButton: {
		type: Boolean,
		default: false,
	},
	displayOrder: {
		type: String,
		default: 'name_first',
	},
})

const emit = defineEmits(['respond', 'openCheckin', 'openDetail'])

// Answers this appointment offers — the buttons must not show one the server
// would reject.
const responseOptions = computed(() => responseOptionsFor(props.item.allowMaybe))

const formattedDate = computed(() => formatDateTime(props.item.subText))
</script>

<style scoped lang="scss">
.appointment-item {
    padding: 0 14px 12px 14px;
    border-bottom: 1px solid var(--color-border);
    overflow: hidden;
    overflow-wrap: break-word;

    &:last-child {
        border-bottom: none;
    }
}

.appointment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        flex: 1;
        margin-inline-end: 8px;

        &.clickable {
            cursor: pointer;

            &:hover {
                text-decoration: underline;
            }
        }
    }

    .appointment-time {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
        flex-shrink: 0;
    }

    .appointment-name {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }

    &.date-first {
        h3 {
            flex: 0 0 auto;
            overflow: visible;
            text-overflow: unset;
        }

        .appointment-name {
            flex: 1;
        }
    }
}

.checkin-section {
    margin-bottom: 8px;

    .checkin-button {
        font-size: 11px;
        padding: 4px 12px;
        min-height: 28px;
        font-weight: 600;
    }
}

.response-section {
    margin-top: 8px;
}
</style>
