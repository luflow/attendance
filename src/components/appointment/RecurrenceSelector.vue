<template>
    <div class="recurrence-selector">
        <NcCheckboxRadioSwitch
            v-model="enabled"
            type="switch"
            :disabled="disabled || !startDate"
            data-test="switch-recurrence"
        >
            {{ t("attendance", "Repeat appointment") }}
        </NcCheckboxRadioSwitch>
        <p v-if="!startDate" class="hint-text">
            {{
                t("attendance", "Set a start date first to enable recurrence.")
            }}
        </p>

        <div v-if="enabled && startDate" class="recurrence-config">
            <div class="frequency-row">
                <NcSelect
                    v-model="selectedFrequency"
                    :options="frequencyOptions"
                    :clearable="false"
                    label="label"
                    :reduce="(opt) => opt.value"
                    class="frequency-select"
                    data-test="select-frequency"
                />

                <span class="interval-label">{{
                    t("attendance", "every")
                }}</span>

                <NcInputField
                    v-model.number="config.interval"
                    type="number"
                    class="interval-input"
                    data-test="input-interval"
                    :input-props="{ min: 1, max: 12 }"
                />

                <span class="interval-unit">{{ intervalUnitLabel }}</span>
            </div>

            <!-- Weekly: day checkboxes -->
            <div
                v-if="config.frequency === 'WEEKLY'"
                class="weekday-checkboxes"
                data-test="weekday-checkboxes"
            >
                <NcCheckboxRadioSwitch
                    v-for="day in weekdays"
                    :key="day.value"
                    :model-value="config.byWeekday.includes(day.value)"
                    :button-variant="true"
                    data-test="checkbox-weekday"
                    @update:model-value="toggleWeekday(day.value)"
                >
                    {{ day.label }}
                </NcCheckboxRadioSwitch>
            </div>

            <!-- Monthly: type selection -->
            <div
                v-if="config.frequency === 'MONTHLY'"
                class="monthly-type"
                data-test="monthly-type"
            >
                <NcCheckboxRadioSwitch
                    v-model="config.monthlyType"
                    value="dayOfMonth"
                    name="monthly-type"
                    type="radio"
                    data-test="radio-day-of-month"
                >
                    {{ monthlyDayOfMonthLabel }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="config.monthlyType"
                    value="weekdayPosition"
                    name="monthly-type"
                    type="radio"
                    data-test="radio-weekday-position"
                >
                    {{ monthlyWeekdayPositionLabel }}
                </NcCheckboxRadioSwitch>
            </div>

            <!-- End condition -->
            <div class="end-condition">
                <h4>{{ t("attendance", "Ends") }}</h4>
                <div class="end-options">
                    <div class="end-option">
                        <NcCheckboxRadioSwitch
                            v-model="config.endType"
                            value="count"
                            name="end-type"
                            type="radio"
                            data-test="radio-end-count"
                        >
                            {{ t("attendance", "After") }}
                        </NcCheckboxRadioSwitch>
                        <NcInputField
                            v-model.number="config.count"
                            type="number"
                            :disabled="config.endType !== 'count'"
                            class="count-input"
                            data-test="input-count"
                            :input-props="{ min: 1, max: 52 }"
                        />
                        <span class="end-label">{{
                            t("attendance", "occurrences")
                        }}</span>
                    </div>
                    <div class="end-option">
                        <NcCheckboxRadioSwitch
                            v-model="config.endType"
                            value="until"
                            name="end-type"
                            type="radio"
                            data-test="radio-end-until"
                        >
                            {{ t("attendance", "On date") }}
                        </NcCheckboxRadioSwitch>
                        <NcDateTimePickerNative
                            id="recurrence-until"
                            :model-value="untilDateObject"
                            type="date"
                            :hide-label="true"
                            :disabled="config.endType !== 'until'"
                            class="until-input"
                            data-test="input-until"
                            @update:model-value="onUntilDateChange"
                        />
                    </div>
                </div>
            </div>

            <!-- Preview -->
            <div
                v-if="occurrences.length > 0"
                class="preview"
                data-test="recurrence-preview"
            >
                <p class="preview-count">
                    {{
                        n(
                            "attendance",
                            "{count} appointment will be created",
                            "{count} appointments will be created",
                            occurrences.length,
                            { count: occurrences.length },
                        )
                    }}
                </p>
                <ul class="preview-list">
                    <li
                        v-for="(date, index) in visibleOccurrences"
                        :key="index"
                    >
                        {{ formatOccurrence(date) }}
                    </li>
                </ul>
                <NcButton
                    v-if="occurrences.length > previewLimit && !showAllPreview"
                    variant="tertiary"
                    data-test="button-show-all"
                    @click="showAllPreview = true"
                >
                    {{ t("attendance", "Show all") }}
                </NcButton>
            </div>

            <!-- Validation warning -->
            <NcNoteCard
                v-if="validationWarning"
                type="warning"
                data-test="recurrence-warning"
            >
                {{ validationWarning }}
            </NcNoteCard>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from "vue";
import {
    NcCheckboxRadioSwitch,
    NcSelect,
    NcInputField,
    NcDateTimePickerNative,
    NcButton,
    NcNoteCard,
} from "@nextcloud/vue";
import {
    generateOccurrences,
    getMonthlyPosition,
} from "../../utils/recurrence.js";
import { formatDateRange } from "../../utils/datetime.js";

const props = defineProps({
    startDate: {
        type: Date,
        default: null,
    },
    duration: {
        type: Number,
        default: 0,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["update:occurrences", "update:validationWarning"]);

const enabled = ref(false);
const showAllPreview = ref(false);
const previewLimit = 5;

const config = reactive({
    frequency: "WEEKLY",
    interval: 1,
    byWeekday: [],
    monthlyType: "dayOfMonth",
    endType: "count",
    count: 10,
    until: null,
});

const selectedFrequency = computed({
    get: () => config.frequency,
    set: (val) => {
        config.frequency = val;
    },
});

const frequencyOptions = [
    { value: "DAILY", label: t("attendance", "Daily") },
    { value: "WEEKLY", label: t("attendance", "Weekly") },
    { value: "MONTHLY", label: t("attendance", "Monthly") },
];

const weekdays = [
    { value: "MO", label: t("attendance", "Mon") },
    { value: "TU", label: t("attendance", "Tue") },
    { value: "WE", label: t("attendance", "Wed") },
    { value: "TH", label: t("attendance", "Thu") },
    { value: "FR", label: t("attendance", "Fri") },
    { value: "SA", label: t("attendance", "Sat") },
    { value: "SU", label: t("attendance", "Sun") },
];

const ordinalLabels = [
    t("attendance", "1st"),
    t("attendance", "2nd"),
    t("attendance", "3rd"),
    t("attendance", "4th"),
    t("attendance", "5th"),
];

const weekdayLabels = {
    MO: t("attendance", "Monday"),
    TU: t("attendance", "Tuesday"),
    WE: t("attendance", "Wednesday"),
    TH: t("attendance", "Thursday"),
    FR: t("attendance", "Friday"),
    SA: t("attendance", "Saturday"),
    SU: t("attendance", "Sunday"),
};

const intervalUnitLabel = computed(() => {
    switch (config.frequency) {
        case "DAILY":
            return n("attendance", "day", "days", config.interval);
        case "WEEKLY":
            return n("attendance", "week", "weeks", config.interval);
        case "MONTHLY":
            return n("attendance", "month", "months", config.interval);
        default:
            return "";
    }
});

const monthlyDayOfMonthLabel = computed(() => {
    const day = props.startDate.getDate();
    return t("attendance", "On day {day} of each month", { day });
});

const monthlyWeekdayPositionLabel = computed(() => {
    const pos = getMonthlyPosition(props.startDate);
    const ordinal = ordinalLabels[pos.n - 1] || `${pos.n}th`;
    const dayName = weekdayLabels[pos.dayKey] || pos.dayKey;
    return t("attendance", "On the {ordinal} {weekday} of each month", {
        ordinal,
        weekday: dayName,
    });
});

const untilDateObject = computed(() => {
    if (!config.until) return null;
    const date =
        config.until instanceof Date ? config.until : new Date(config.until);
    return isNaN(date.getTime()) ? null : date;
});

const onUntilDateChange = (newValue) => {
    config.until = newValue || null;
};

const toggleWeekday = (day) => {
    const idx = config.byWeekday.indexOf(day);
    if (idx >= 0) {
        config.byWeekday.splice(idx, 1);
    } else {
        config.byWeekday.push(day);
    }
};

// Auto-select current day of week when enabling weekly
watch(
    () => config.frequency,
    (freq) => {
        if (
            freq === "WEEKLY" &&
            config.byWeekday.length === 0 &&
            props.startDate
        ) {
            const dayKeys = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"];
            const startDayKey = dayKeys[props.startDate.getDay()];
            config.byWeekday = [startDayKey];
        }
    },
);

const validationWarning = computed(() => {
    if (!enabled.value || !props.startDate) return null;
    if (config.frequency === "WEEKLY" && config.byWeekday.length === 0) {
        return t("attendance", "Select at least one day of the week.");
    }
    if (config.endType === "count" && (!config.count || config.count < 1)) {
        return t(
            "attendance",
            "Please enter an occurrence count greater than 0.",
        );
    }
    if (config.endType === "until" && !config.until) {
        return t("attendance", "Please select an end date for recurrence.");
    }
    if (config.endType === "until" && config.until) {
        const untilDate =
            config.until instanceof Date
                ? config.until
                : new Date(config.until);
        if (untilDate <= props.startDate) {
            return t("attendance", "End date must be after the start date.");
        }
    }
    return null;
});

// Generate occurrences reactively
const occurrences = computed(() => {
    if (!enabled.value || !props.startDate || validationWarning.value) {
        return [];
    }
    return generateOccurrences(config, props.startDate);
});

const visibleOccurrences = computed(() => {
    if (showAllPreview.value) return occurrences.value;
    return occurrences.value.slice(0, previewLimit);
});

const formatOccurrence = (date) => {
    if (props.duration > 0) {
        const endDate = new Date(date.getTime() + props.duration);
        return formatDateRange(date, endDate);
    }
    return formatDateRange(date, null);
};

// Emit occurrences whenever they change
watch(
    occurrences,
    (newOccurrences) => {
        emit("update:occurrences", newOccurrences);
    },
    { immediate: true },
);

// Emit validation warning whenever it changes
watch(
    validationWarning,
    (warning) => {
        emit("update:validationWarning", warning);
    },
    { immediate: true },
);

// Reset showAllPreview when config changes
watch(
    [
        () => config.frequency,
        () => config.interval,
        () => config.endType,
        () => config.count,
        () => config.until,
        () => config.byWeekday.length,
        () => config.monthlyType,
    ],
    () => {
        showAllPreview.value = false;
    },
);

// When disabled, emit empty array
watch(enabled, (isEnabled) => {
    if (!isEnabled) {
        emit("update:occurrences", []);
    }
});

// Auto-select weekday when start date changes
watch(
    () => props.startDate,
    (newDate) => {
        if (
            newDate &&
            config.frequency === "WEEKLY" &&
            config.byWeekday.length === 0
        ) {
            const dayKeys = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"];
            config.byWeekday = [dayKeys[newDate.getDay()]];
        }
    },
);
</script>

<style scoped lang="scss">
.recurrence-selector {
    .hint-text {
        font-size: 12px;
        color: var(--color-text-maxcontrast);
        margin: 4px 0 0 0;
    }
}

.recurrence-config {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-top: 12px;
    padding-left: 4px;
}

.frequency-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;

    .frequency-select {
        min-width: 160px;
        max-width: 200px;
    }

    .interval-label {
        color: var(--color-text-maxcontrast);
    }

    .interval-input {
        max-width: 80px;
    }

    .interval-unit {
        color: var(--color-text-maxcontrast);
    }
}

.weekday-checkboxes {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;

    :deep(.checkbox-content) {
        border-radius: 6px !important;
    }
}

.monthly-type {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.end-condition {
    h4 {
        margin: 0 0 8px 0;
        font-size: 14px;
        font-weight: 600;
    }

    .end-options {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .end-option {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;

        .count-input {
            max-width: 80px;
        }

        .until-input {
            max-width: 200px;
        }

        .end-label {
            color: var(--color-text-maxcontrast);
        }
    }
}

.preview {
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    padding: 12px;

    .preview-count {
        font-weight: 600;
        margin: 0 0 8px 0;
        font-size: 14px;
    }

    .preview-list {
        margin: 0;
        padding: 0 0 0 20px;
        font-size: 13px;
        color: var(--color-text-maxcontrast);

        li {
            margin-bottom: 2px;
        }
    }
}
</style>
