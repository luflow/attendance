<template>
    <NcModal
        v-if="show"
        @close="$emit('close')"
        :name="t('attendance', 'Export appointments')"
        size="normal"
    >
        <div class="export-dialog">
            <h2>{{ t("attendance", "Export appointments") }}</h2>

            <!-- Filter Type Selection -->
            <div class="filter-section">
                <h3>{{ t("attendance", "Filter options") }}</h3>

                <div class="radio-group">
                    <NcCheckboxRadioSwitch
                        v-model="filterType"
                        value="all"
                        name="filter_type"
                        type="radio"
                    >
                        {{ t("attendance", "All appointments") }}
                    </NcCheckboxRadioSwitch>

                    <NcCheckboxRadioSwitch
                        v-model="filterType"
                        value="selected"
                        name="filter_type"
                        type="radio"
                    >
                        {{ t("attendance", "Selected appointments") }}
                    </NcCheckboxRadioSwitch>

                    <NcCheckboxRadioSwitch
                        v-model="filterType"
                        value="dateRange"
                        name="filter_type"
                        type="radio"
                    >
                        {{ t("attendance", "Date range") }}
                    </NcCheckboxRadioSwitch>
                </div>
            </div>

            <!-- Selected Appointments -->
            <div v-if="filterType === 'selected'" class="filter-section">
                <div class="event-list-header">
                    <label class="section-label">{{
                        t("attendance", "Select appointments")
                    }}</label>
                    <div class="selection-actions">
                        <NcButton
                            variant="tertiary"
                            :disabled="
                                selectedAppointments.length ===
                                availableAppointments.length
                            "
                            @click="selectAllAppointments"
                        >
                            {{ t("attendance", "Select all") }}
                        </NcButton>
                        <NcButton
                            variant="tertiary"
                            :disabled="selectedAppointments.length === 0"
                            @click="deselectAllAppointments"
                        >
                            {{ t("attendance", "Deselect all") }}
                        </NcButton>
                    </div>
                </div>

                <ul class="event-list">
                    <li
                        v-for="appointment in availableAppointments"
                        :key="appointment.id"
                        class="event-item"
                        @click="toggleAppointment(appointment.id)"
                    >
                        <NcCheckboxRadioSwitch
                            :model-value="
                                selectedAppointments.includes(appointment.id)
                            "
                            class="event-checkbox"
                        >
                            <div class="event-info">
                                <span class="event-name">{{
                                    appointment.name
                                }}</span>
                                <span class="event-date">{{
                                    formatDateTime(appointment.startDatetime)
                                }}</span>
                            </div>
                        </NcCheckboxRadioSwitch>
                    </li>
                </ul>
            </div>

            <!-- Date Range Options -->
            <div v-if="filterType === 'dateRange'" class="filter-section">
                <h3>{{ t("attendance", "Date range") }}</h3>

                <div class="radio-group">
                    <NcCheckboxRadioSwitch
                        v-model="dateRangePreset"
                        value="month"
                        name="date_preset"
                        type="radio"
                    >
                        {{ t("attendance", "Current month") }}
                    </NcCheckboxRadioSwitch>

                    <NcCheckboxRadioSwitch
                        v-model="dateRangePreset"
                        value="quarter"
                        name="date_preset"
                        type="radio"
                    >
                        {{ t("attendance", "Current quarter") }}
                    </NcCheckboxRadioSwitch>

                    <NcCheckboxRadioSwitch
                        v-model="dateRangePreset"
                        value="year"
                        name="date_preset"
                        type="radio"
                    >
                        {{ t("attendance", "Current year") }}
                    </NcCheckboxRadioSwitch>

                    <NcCheckboxRadioSwitch
                        v-model="dateRangePreset"
                        value="custom"
                        name="date_preset"
                        type="radio"
                    >
                        {{ t("attendance", "Custom range") }}
                    </NcCheckboxRadioSwitch>
                </div>

                <!-- Custom Date Range Inputs -->
                <div v-if="dateRangePreset === 'custom'" class="date-inputs">
                    <div class="date-input">
                        <label>{{ t("attendance", "Start date") }}</label>
                        <input
                            v-model="customStartDate"
                            type="date"
                            class="date-field"
                        />
                    </div>
                    <div class="date-input">
                        <label>{{ t("attendance", "End date") }}</label>
                        <input
                            v-model="customEndDate"
                            type="date"
                            class="date-field"
                        />
                    </div>
                </div>

                <!-- Date Range Preview -->
                <div v-if="dateRangePreset !== 'custom'" class="date-preview">
                    <p>
                        <strong>{{ t("attendance", "Date range") }}:</strong>
                        {{ getDateRangePreview() }}
                    </p>
                </div>
            </div>

            <!-- Export Options -->
            <div class="filter-section">
                <h3>{{ t("attendance", "Export options") }}</h3>
                <NcCheckboxRadioSwitch
                    v-model="includeComments"
                    type="checkbox"
                >
                    {{ t("attendance", "Include comments in export") }}
                </NcCheckboxRadioSwitch>
            </div>

            <!-- Export Button -->
            <div class="button-row">
                <NcButton
                    :disabled="!canExport || exporting"
                    variant="primary"
                    @click="handleExport"
                >
                    <template #icon>
                        <NcLoadingIcon v-if="exporting" :size="20" />
                        <DownloadIcon v-else :size="20" />
                    </template>
                    {{
                        exporting
                            ? t("attendance", "Exporting …")
                            : t("attendance", "Export")
                    }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script setup>
import { ref, computed, watch } from "vue";
import { generateUrl } from "@nextcloud/router";
import { showSuccess, showError } from "@nextcloud/dialogs";
import { translate as t } from "@nextcloud/l10n";
import axios from "@nextcloud/axios";

import {
    NcModal,
    NcButton,
    NcCheckboxRadioSwitch,
    NcLoadingIcon,
} from "@nextcloud/vue";

import DownloadIcon from "vue-material-design-icons/Download.vue";

import { formatDateTime } from "../utils/datetime.js";

const props = defineProps({
    show: {
        type: Boolean,
        required: true,
    },
    availableAppointments: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(["close"]);

// Export filter options
const filterType = ref("all");
const selectedAppointments = ref([]);
const dateRangePreset = ref("month");
const customStartDate = ref("");
const customEndDate = ref("");
const includeComments = ref(false);
const exporting = ref(false);

// Watch filter type changes to reset selections
watch(filterType, (newType) => {
    if (newType !== "selected") {
        selectedAppointments.value = [];
    }
});

// Computed properties
const canExport = computed(() => {
    if (filterType.value === "all") return true;
    if (filterType.value === "selected")
        return selectedAppointments.value.length > 0;
    if (
        filterType.value === "dateRange" &&
        dateRangePreset.value === "custom"
    ) {
        return customStartDate.value !== "" && customEndDate.value !== "";
    }
    return filterType.value === "dateRange";
});

const getDateRangePreview = () => {
    const now = new Date();

    switch (dateRangePreset.value) {
        case "month": {
            const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            return `${formatDate(monthStart)} - ${formatDate(monthEnd)}`;
        }
        case "quarter": {
            const quarter = Math.floor(now.getMonth() / 3);
            const quarterStart = new Date(now.getFullYear(), quarter * 3, 1);
            const quarterEnd = new Date(now.getFullYear(), quarter * 3 + 3, 0);
            return `${formatDate(quarterStart)} - ${formatDate(quarterEnd)}`;
        }
        case "year": {
            const yearStart = new Date(now.getFullYear(), 0, 1);
            const yearEnd = new Date(now.getFullYear(), 11, 31);
            return `${formatDate(yearStart)} - ${formatDate(yearEnd)}`;
        }
        default:
            return "";
    }
};

const formatDate = (date) => {
    return date.toLocaleDateString();
};

const toggleAppointment = (id) => {
    const index = selectedAppointments.value.indexOf(id);
    if (index === -1) {
        selectedAppointments.value = [...selectedAppointments.value, id];
    } else {
        selectedAppointments.value = selectedAppointments.value.filter(
            (a) => a !== id,
        );
    }
};

const selectAllAppointments = () => {
    selectedAppointments.value = props.availableAppointments.map(
        (appointment) => appointment.id,
    );
};

const deselectAllAppointments = () => {
    selectedAppointments.value = [];
};

const handleExport = async () => {
    if (!canExport.value) return;

    exporting.value = true;

    try {
        const exportData = buildExportData();
        const response = await axios.post(
            generateUrl("/apps/attendance/api/export"),
            exportData,
        );

        if (response.data.success) {
            showSuccess(
                t("attendance", "Export created: {filename}", {
                    filename: response.data.filename,
                }),
            );

            // Redirect to Files app to show the exported file
            const filesUrl = generateUrl("/apps/files/?dir=/Attendance");
            window.location.href = filesUrl;

            emit("close");
        } else {
            showError(t("attendance", "Failed to export appointments"));
        }
    } catch (error) {
        console.error("Failed to export appointments:", error);
        const errorMessage =
            error.response?.data?.error ||
            t("attendance", "Failed to export appointments");
        showError(errorMessage);
    } finally {
        exporting.value = false;
    }
};

const getCalculatedDateRange = () => {
    const now = new Date();

    switch (dateRangePreset.value) {
        case "month": {
            const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            return {
                startDate: formatIsoDate(monthStart),
                endDate: formatIsoDate(monthEnd),
            };
        }
        case "quarter": {
            const quarter = Math.floor(now.getMonth() / 3);
            const quarterStart = new Date(now.getFullYear(), quarter * 3, 1);
            const quarterEnd = new Date(now.getFullYear(), quarter * 3 + 3, 0);
            return {
                startDate: formatIsoDate(quarterStart),
                endDate: formatIsoDate(quarterEnd),
            };
        }
        case "year": {
            const yearStart = new Date(now.getFullYear(), 0, 1);
            const yearEnd = new Date(now.getFullYear(), 11, 31);
            return {
                startDate: formatIsoDate(yearStart),
                endDate: formatIsoDate(yearEnd),
            };
        }
        case "custom":
            return {
                startDate: customStartDate.value,
                endDate: customEndDate.value,
            };
        default:
            return {};
    }
};

const formatIsoDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
};

const buildExportData = () => {
    const data = {
        includeComments: includeComments.value,
    };

    if (filterType.value === "selected") {
        data.appointmentIds = selectedAppointments.value;
    } else if (filterType.value === "dateRange") {
        const { startDate, endDate } = getCalculatedDateRange();
        data.startDate = startDate;
        data.endDate = endDate;
    }

    return data;
};

// Reset form when dialog closes
watch(
    () => props.show,
    (show) => {
        if (!show) {
            filterType.value = "all";
            selectedAppointments.value = [];
            dateRangePreset.value = "month";
            customStartDate.value = "";
            customEndDate.value = "";
            includeComments.value = false;
            exporting.value = false;
        }
    },
);
</script>

<style scoped>
.export-dialog {
    padding: 20px;
    min-width: 500px;
}

.export-dialog h2 {
    margin: 0 0 20px 0;
    font-size: 1.5em;
}

.export-dialog h3 {
    margin: 20px 0 10px 0;
    font-size: 1.2em;
}

.filter-section {
    margin-bottom: 20px;
}

.info-card {
    margin-bottom: 15px;
}

.radio-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.section-label {
    display: block;
    margin-bottom: 12px;
    font-weight: 600;
}

.event-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 4px;
}

.event-list-header .section-label {
    margin-bottom: 0;
}

.selection-actions {
    display: flex;
    gap: 4px;
}

.event-list {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 300px;
    overflow-y: auto;
}

.event-item {
    display: flex;
    align-items: center;
    padding: 12px 12px 12px 6px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    margin-bottom: 8px;
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.event-item:hover {
    background-color: var(--color-background-hover);
}

.event-checkbox {
    pointer-events: none;
    width: 100%;
    margin: -4px 0;
}

.event-checkbox :deep(.checkbox-content__icon) {
    margin-block: auto !important;
    margin-inline-end: 8px;
}

.event-name {
    flex: 1;
    font-weight: 500;
}

.event-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.event-date {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.date-inputs {
    display: flex;
    gap: 20px;
    margin-top: 15px;
}

.date-input {
    flex: 1;
}

.date-input label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.date-field {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
}

.date-preview {
    margin-top: 10px;
    padding: 10px;
    background-color: var(--color-background-hover);
    border-radius: var(--border-radius);
}

.button-row {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--color-border);
}
</style>
