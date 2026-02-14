<template>
    <div class="appointment-card" data-test="appointment-card">
        <div class="appointment-header">
            <div class="appointment-title-block">
                <h3 data-test="appointment-title">
                    {{ appointment.name }}
                </h3>
                <span class="appointment-date-subtitle">
                    {{
                        formatDateRange(
                            appointment.startDatetime,
                            appointment.endDatetime,
                        )
                    }}
                    <a
                        v-if="calendarLink"
                        :href="calendarLink"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="calendar-link"
                        :title="t('attendance', 'Imported from calendar')"
                    >
                        <CalendarSyncIcon :size="14" />
                    </a>
                </span>
            </div>
            <div class="appointment-actions">
                <NcActions
                    :force-menu="true"
                    data-test="appointment-actions-menu"
                >
                    <NcActionButton
                        :close-after-click="true"
                        data-test="action-share-link"
                        @click="copyShareLink"
                    >
                        <template #icon>
                            <ShareVariantIcon :size="20" />
                        </template>
                        {{ t("attendance", "Share link") }}
                    </NcActionButton>
                    <NcActionButton
                        v-if="canCheckin"
                        :close-after-click="true"
                        data-test="action-start-checkin"
                        @click="handleStartCheckin"
                    >
                        <template #icon>
                            <ListStatusIcon :size="20" />
                        </template>
                        {{ t("attendance", "Start check-in") }}
                    </NcActionButton>
                    <NcActionButton
                        v-if="canManageAppointments"
                        :close-after-click="true"
                        data-test="action-edit"
                        @click="handleEdit"
                    >
                        <template #icon>
                            <Pencil :size="20" />
                        </template>
                        {{ t("attendance", "Edit") }}
                    </NcActionButton>
                    <NcActionButton
                        v-if="canManageAppointments"
                        :close-after-click="true"
                        data-test="action-copy"
                        @click="handleCopy"
                    >
                        <template #icon>
                            <ContentCopy :size="20" />
                        </template>
                        {{ t("attendance", "Copy") }}
                    </NcActionButton>
                    <NcActionButton
                        v-if="canManageAppointments"
                        :close-after-click="true"
                        data-test="action-delete"
                        @click="handleDelete"
                    >
                        <template #icon>
                            <Delete :size="20" />
                        </template>
                        {{ t("attendance", "Delete") }}
                    </NcActionButton>
                </NcActions>
            </div>
        </div>

        <div
            v-if="appointment.description"
            class="appointment-description"
            v-html="renderedDescription"
        />

        <div
            v-if="appointment.attachments?.length"
            class="attachment-chips"
            data-test="attachment-chips"
        >
            <a
                v-for="attachment in appointment.attachments"
                :key="attachment.fileId"
                :href="getAttachmentUrl(attachment)"
                target="_blank"
                rel="noopener noreferrer"
                class="attachment-link"
                :data-test="`attachment-link-${attachment.fileId}`"
            >
                <NcChip :text="attachment.fileName" no-close>
                    <template #icon>
                        <Paperclip :size="16" />
                    </template>
                </NcChip>
            </a>
        </div>

        <!-- Response Section -->
        <div class="response-section" data-test="response-section">
            <h4>{{ t("attendance", "Your response") }}</h4>
            <div
                class="response-buttons"
                :class="{ 'has-response': userResponse }"
            >
                <NcButton
                    :class="{ active: userResponse === 'yes' }"
                    variant="success"
                    :text="t('attendance', 'Yes')"
                    data-test="response-yes"
                    @click="handleResponse('yes')"
                />
                <NcButton
                    :class="{ active: userResponse === 'maybe' }"
                    variant="warning"
                    :text="t('attendance', 'Maybe')"
                    data-test="response-maybe"
                    @click="handleResponse('maybe')"
                />
                <NcButton
                    :class="{ active: userResponse === 'no' }"
                    variant="error"
                    :text="t('attendance', 'No')"
                    data-test="response-no"
                    @click="handleResponse('no')"
                />
                <!-- Comment Toggle Button (only show when user has responded) -->
                <NcButton
                    v-if="userResponse"
                    :class="{
                        'comment-active': commentExpanded,
                        'comment-toggle': true,
                    }"
                    type="tertiary"
                    data-test="button-toggle-comment"
                    @click="toggleComment"
                >
                    <template #icon>
                        <CommentIcon :size="20" />
                    </template>
                </NcButton>
            </div>

            <!-- Comment Section -->
            <div v-if="commentExpanded" class="comment-section">
                <div class="textarea-container">
                    <NcInputField
                        ref="commentInput"
                        v-model="localComment"
                        type="text"
                        :label="t('attendance', 'Comment (optional)')"
                        :placeholder="t('attendance', 'Add your comment …')"
                        data-test="response-comment"
                        @update:model-value="handleCommentInputEvent"
                    />

                    <div v-if="savingComment" class="saving-spinner">
                        <div class="spinner" />
                    </div>
                    <div v-else-if="commentSaved" class="saved-indicator">
                        <CheckIcon :size="16" class="check-icon" />
                    </div>
                    <div v-else-if="errorComment" class="error-indicator">
                        <CloseCircle :size="16" class="error-icon" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Checkin Summary (only shown when checkins exist and user can see response overview) -->
        <div
            v-if="
                canSeeResponseOverview &&
                appointment.checkinSummary?.hasCheckins
            "
            class="checkin-summary"
            data-test="checkin-summary"
        >
            <h4>{{ t("attendance", "Check-in summary") }}</h4>
            <div class="summary-stats">
                <NcChip
                    :text="
                        t('attendance', '{count} attended', {
                            count: appointment.checkinSummary.attended,
                        })
                    "
                    variant="success"
                    no-close
                >
                    <template #icon>
                        <CheckIcon :size="16" />
                    </template>
                </NcChip>
                <NcChip
                    :text="
                        t('attendance', '{count} absent', {
                            count: appointment.checkinSummary.absent,
                        })
                    "
                    variant="error"
                    no-close
                >
                    <template #icon>
                        <CloseIcon :size="16" />
                    </template>
                </NcChip>
                <NcChip
                    v-if="appointment.checkinSummary.notCheckedIn > 0"
                    :text="
                        t('attendance', '{count} pending', {
                            count: appointment.checkinSummary.notCheckedIn,
                        })
                    "
                    variant="tertiary"
                    no-close
                >
                    <template #icon>
                        <HelpCircleOutlineIcon :size="16" />
                    </template>
                </NcChip>
            </div>
        </div>

        <!-- Detailed Response Summary -->
        <ResponseSummary
            v-if="canSeeResponseOverview && appointment.responseSummary"
            :response-summary="appointment.responseSummary"
            :can-see-comments="canSeeComments"
        />
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from "vue";
import {
    NcButton,
    NcActions,
    NcActionButton,
    NcInputField,
    NcChip,
} from "@nextcloud/vue";
import ResponseSummary from "./ResponseSummary.vue";
import { renderMarkdown, sanitizeHtml } from "../../utils/markdown.js";
import { generateUrl } from "@nextcloud/router";
import { showSuccess } from "@nextcloud/dialogs";
import ListStatusIcon from "vue-material-design-icons/ListStatus.vue";
import ShareVariantIcon from "vue-material-design-icons/ShareVariant.vue";
import Pencil from "vue-material-design-icons/Pencil.vue";
import Delete from "vue-material-design-icons/Delete.vue";
import ContentCopy from "vue-material-design-icons/ContentCopy.vue";
import CheckIcon from "vue-material-design-icons/Check.vue";
import CloseIcon from "vue-material-design-icons/Close.vue";
import CloseCircle from "vue-material-design-icons/CloseCircle.vue";
import HelpCircleOutlineIcon from "vue-material-design-icons/HelpCircleOutline.vue";
import Paperclip from "vue-material-design-icons/Paperclip.vue";
import CommentIcon from "vue-material-design-icons/Comment.vue";
import CalendarSyncIcon from "vue-material-design-icons/CalendarSync.vue";
import { formatDateRange } from "../../utils/datetime.js";
import { useAppointmentResponse } from "../../composables/useAppointmentResponse.js";

const props = defineProps({
    appointment: {
        type: Object,
        required: true,
    },
    canManageAppointments: {
        type: Boolean,
        default: false,
    },
    canCheckin: {
        type: Boolean,
        default: false,
    },
    canSeeResponseOverview: {
        type: Boolean,
        default: true,
    },
    canSeeComments: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits([
    "start-checkin",
    "edit",
    "copy",
    "delete",
    "submit-response",
    "update-comment",
]);

const localComment = ref(props.appointment.userResponse?.comment || "");
const commentExpanded = ref(false);
const commentInput = ref(null);
let commentTimeout = null;

// Use the shared response composable for comment auto-save
const { savingComment, commentSaved, errorComment, autoSaveComment } =
    useAppointmentResponse();

const toggleComment = async () => {
    commentExpanded.value = !commentExpanded.value;
    if (commentExpanded.value) {
        await nextTick();
        commentInput.value?.$el?.querySelector("input")?.focus();
    }
};

const userResponse = computed(() => {
    return props.appointment.userResponse?.response || null;
});

const renderedDescription = computed(() => {
    if (!props.appointment.description) return "";
    const html = renderMarkdown(props.appointment.description, false);
    return sanitizeHtml(html);
});

const calendarLink = computed(() => {
    if (
        !props.appointment.calendarUri ||
        !props.appointment.calendarEventUid ||
        !props.appointment.startDatetime
    ) {
        return null;
    }

    // Generate deeplink to open the event popup directly in Calendar app
    // URL format: /apps/calendar/{view}/{date}/edit/popover/{base64_dav_path}/{recurrenceId}
    const dateObj = new Date(props.appointment.startDatetime);
    const year = dateObj.getFullYear();
    const month = String(dateObj.getMonth() + 1).padStart(2, "0");
    const day = String(dateObj.getDate()).padStart(2, "0");
    const dateStr = `${year}-${month}-${day}`;

    // Get current user from Nextcloud
    const currentUser = window.OC?.currentUser || "admin";

    // calendarEventUid contains the filename (e.g., "70EB1F77-0025-44EB-88B3-B64F65CC3F84.ics")
    const eventUri = props.appointment.calendarEventUid;

    // Build the DAV path: /remote.php/dav/calendars/{user}/{calendar}/{filename}
    const davPath = `/remote.php/dav/calendars/${currentUser}/${props.appointment.calendarUri}/${eventUri}`;

    // Base64 encode the path
    const base64Path = btoa(davPath);

    // For non-recurring events, use "next" as recurrenceId
    const recurrenceId = "next";

    return generateUrl(
        `/apps/calendar/dayGridMonth/${dateStr}/edit/popover/${base64Path}/${recurrenceId}`,
    );
});

watch(
    () => props.appointment.userResponse,
    (newResponse) => {
        if (!commentTimeout) {
            localComment.value = newResponse?.comment || "";
        }
    },
    { immediate: true, deep: true },
);

const copyShareLink = async () => {
    const appointmentUrl =
        window.location.origin +
        generateUrl(`/apps/attendance/appointment/${props.appointment.id}`);

    try {
        await navigator.clipboard.writeText(appointmentUrl);
        showSuccess(window.t("attendance", "Link copied to clipboard"));
    } catch (error) {
        console.error("Failed to copy link:", error);
    }
};

const handleStartCheckin = () => {
    emit("start-checkin", props.appointment.id);
};

const handleEdit = () => {
    emit("edit", props.appointment);
};

const handleCopy = () => {
    emit("copy", props.appointment);
};

const handleDelete = () => {
    emit("delete", props.appointment.id);
};

const handleResponse = (response) => {
    emit("submit-response", props.appointment.id, response);
};

const getAttachmentUrl = (attachment) => {
    return attachment.downloadUrl || generateUrl(`/f/${attachment.fileId}`);
};

const handleCommentInputEvent = () => {
    if (commentTimeout) {
        clearTimeout(commentTimeout);
    }

    commentTimeout = setTimeout(async () => {
        // Wait for Vue to update the DOM and reactive values
        await nextTick();
        autoSaveComment(
            props.appointment.id,
            userResponse.value,
            localComment.value,
        );
    }, 500);
};
</script>

<style scoped lang="scss">
@use "../../styles/shared.scss";

.appointment-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;
    margin-bottom: 20px;
}

.appointment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    background: var(--color-background-hover);
    margin: -20px -20px 20px -20px;
    padding: 20px;
    border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;

    .appointment-title-block {
        flex: 1;
        min-width: 0;

        h3 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 700;
            color: var(--color-main-text);
        }

        .appointment-date-subtitle {
            display: block;
            font-size: 15px;
            font-weight: 500;
            color: var(--color-text-maxcontrast);
            margin-top: 4px;
        }

        .calendar-link {
            display: inline-flex;
            align-items: center;
            vertical-align: middle;
            margin-left: 4px;
            color: var(--color-primary-element);
        }
    }

    .appointment-actions {
        margin-left: 10px;
    }
}

.appointment-description {
    color: var(--color-text-lighter);
    margin-bottom: 15px;

    // Markdown formatting
    :deep(strong) {
        font-weight: bold;
        color: var(--color-main-text);
    }

    :deep(em) {
        font-style: italic;
    }

    :deep(del) {
        text-decoration: line-through;
    }

    :deep(a) {
        color: var(--color-primary-element);
        text-decoration: none;

        &:hover {
            text-decoration: underline;
        }
    }

    :deep(code) {
        background-color: var(--color-background-dark);
        padding: 2px 6px;
        border-radius: var(--border-radius-small);
        font-family: monospace;
        font-size: 0.9em;
    }

    :deep(pre) {
        background-color: var(--color-background-dark);
        padding: 12px;
        border-radius: var(--border-radius);
        overflow-x: auto;
        margin: 10px 0;

        code {
            background: none;
            padding: 0;
        }
    }

    :deep(blockquote) {
        border-left: 3px solid var(--color-primary-element);
        margin: 10px 0;
        padding-left: 15px;
        color: var(--color-text-maxcontrast);
    }

    :deep(ul) {
        margin: 10px 0;
        padding-left: 25px;
        list-style-type: disc;
    }

    :deep(ol) {
        margin: 10px 0;
        padding-left: 25px;
        list-style-type: decimal;
    }

    :deep(li) {
        margin: 5px 0;
        display: list-item;
    }

    :deep(h1),
    :deep(h2),
    :deep(h3),
    :deep(h4),
    :deep(h5),
    :deep(h6) {
        color: var(--color-main-text);
        margin: 15px 0 10px 0;
        font-weight: 600;
    }

    :deep(h1) {
        font-size: 1.5em;
    }
    :deep(h2) {
        font-size: 1.3em;
    }
    :deep(h3) {
        font-size: 1.15em;
    }
    :deep(h4) {
        font-size: 1.05em;
    }

    :deep(hr) {
        border: none;
        border-top: 1px solid var(--color-border);
        margin: 15px 0;
    }

    :deep(table) {
        border-collapse: collapse;
        width: 100%;
        margin: 10px 0;
    }

    :deep(th),
    :deep(td) {
        border: 1px solid var(--color-border);
        padding: 8px 12px;
        text-align: left;
    }

    :deep(th) {
        background-color: var(--color-background-dark);
        font-weight: 600;
    }

    :deep(p) {
        margin: 10px 0;

        &:first-child {
            margin-top: 0;
        }

        &:last-child {
            margin-bottom: 0;
        }
    }

    :deep(img) {
        max-width: 100%;
        height: auto;
        border-radius: var(--border-radius);
    }
}

.attachment-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 15px;

    .attachment-link {
        text-decoration: none;
        color: inherit;

        &:hover :deep(.nc-chip) {
            background-color: var(--color-background-hover);
        }
    }
}

.checkin-summary {
    border-top: 1px solid var(--color-border);
    padding-top: 15px;
    margin-top: 15px;

    h4 {
        font-size: 0.85em;
        color: var(--color-text-maxcontrast);
        margin: 0 0 10px 0;
    }

    .summary-stats {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
}

.appointment-description + .response-section,
.attachment-chips + .response-section {
    border-top: 1px solid var(--color-border);
    padding-top: 15px;
}

.response-section {
    margin-top: 15px;

    h4 {
        font-size: 1.2em;
        margin: 0 0 10px 0;
    }

    .response-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;

        // When a response exists, gray out non-active buttons (except comment toggle)
        &.has-response {
            :deep(.button-vue:not(.active):not(.comment-toggle)) {
                background-color: var(--color-background-dark) !important;
                color: var(--color-text-lighter) !important;
                border-color: var(--color-border-dark) !important;

                &:hover {
                    background-color: var(--color-background-hover) !important;
                    color: var(--color-text) !important;
                }
            }
        }

        // Active button styles - keep bold
        :deep(.button-vue.active) {
            font-weight: bold;
        }

        // Comment toggle active state
        :deep(.button-vue.comment-active) {
            background-color: var(--color-primary-element) !important;
            color: white !important;
        }
    }

    .comment-section {
        margin-top: 10px;

        .textarea-container {
            position: relative;
        }
    }
}
</style>
