<template>
    <NcDialog
        v-if="show"
        :name="t('attendance', 'Delete appointment')"
        @closing="$emit('cancel')"
    >
        <!-- Series appointment: show scope options -->
        <template v-if="isSeries">
            <p>
                {{
                    t(
                        "attendance",
                        "This appointment is part of a series of {count} appointments. How would you like to apply this change?",
                        { count: seriesCount },
                    )
                }}
            </p>

            <div class="series-options">
                <NcCheckboxRadioSwitch
                    v-model="selectedScope"
                    value="single"
                    name="delete-scope"
                    type="radio"
                >
                    {{ t("attendance", "This appointment only") }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="selectedScope"
                    value="future"
                    name="delete-scope"
                    type="radio"
                >
                    {{
                        t(
                            "attendance",
                            "This and all future appointments",
                        )
                    }}
                </NcCheckboxRadioSwitch>
                <NcCheckboxRadioSwitch
                    v-model="selectedScope"
                    value="all"
                    name="delete-scope"
                    type="radio"
                >
                    {{
                        t(
                            "attendance",
                            "All appointments in this series",
                        )
                    }}
                </NcCheckboxRadioSwitch>
            </div>
        </template>

        <!-- Standalone appointment: simple confirmation -->
        <template v-else>
            <p>
                {{
                    t(
                        "attendance",
                        "Do you want to delete this appointment?",
                    )
                }}
            </p>
        </template>

        <template #actions>
            <NcButton variant="tertiary" @click="$emit('cancel')">
                {{ t("attendance", "Cancel") }}
            </NcButton>
            <NcButton
                variant="error"
                @click="$emit('confirm', selectedScope)"
            >
                {{ t("attendance", "Delete") }}
            </NcButton>
        </template>
    </NcDialog>
</template>

<script setup>
import { ref, computed, watch } from "vue";
import {
    NcDialog,
    NcButton,
    NcCheckboxRadioSwitch,
} from "@nextcloud/vue";

const props = defineProps({
    show: {
        type: Boolean,
        required: true,
    },
    appointment: {
        type: Object,
        default: null,
    },
});

defineEmits(["confirm", "cancel"]);

const isSeries = computed(() => !!props.appointment?.seriesId);
const seriesCount = computed(() => props.appointment?.seriesCount || 0);

const selectedScope = ref("single");

// Reset scope when dialog opens
watch(() => props.show, (newVal) => {
    if (newVal) {
        selectedScope.value = "single";
    }
});
</script>

<style scoped lang="scss">
.series-options {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 12px 0;
}
</style>
