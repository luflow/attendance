<template>
	<component
		:is="icon"
		:size="18"
		class="response-dot"
		:class="`response-dot--${getResponseVariant(response)}`"
		:title="title" />
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import ProgressQuestion from 'vue-material-design-icons/ProgressQuestion.vue'
import { getCheckinText, getResponseIcon, getResponseText, getResponseVariant } from '../../utils/response.js'

const props = defineProps({
	// Null where a list also holds people who never answered, as the statistics
	// drill-down does — the response overview only lists actual answers.
	response: {
		type: String,
		default: null,
	},
	// The same yes/no glyphs serve check-in states, which are never worded as
	// an answer — "Present", not "Yes".
	kind: {
		type: String,
		default: 'response',
	},
})

// The same glyphs the answer buttons and the sidebar use, resolved through the
// shared helper so all three stay in step.
const ICONS = { CheckCircle, HelpCircle, CloseCircle, ProgressQuestion }
const icon = computed(() => ICONS[getResponseIcon(props.response)] ?? HelpCircle)
const title = computed(() => {
	if (props.kind === 'checkin') {
		return getCheckinText(props.response)
	}
	return props.response ? getResponseText(props.response) : t('attendance', 'No response')
})
</script>

<style scoped lang="scss">
@use "sass:math";
@use "../../styles/shared.scss";

.response-dot {
    position: relative;
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    color: var(--color-text-maxcontrast);

    // The icon paints above the backdrop below: an absolutely positioned
    // ::before would otherwise cover the static <svg>.
    :deep(svg) {
        position: relative;
    }

    &--success {
        color: shared.$color-yes;
    }

    &--warning {
        color: shared.$color-maybe;

        // These glyphs are filled discs with the mark *knocked out*, so
        // whatever sits behind shows through it. Green and red are dark enough
        // that the card background reads as the mark; amber is not, in either
        // theme. Rather than compromise the hue, tuck a dark disc exactly
        // behind the icon's own circle — the mark then reads black on amber
        // everywhere, which is the pairing the design uses for "maybe".
        &::before {
            content: "";
            position: absolute;
            // The MDI circle is r=10 about (12,12) in a 24-unit viewBox.
            inset: math.percentage(math.div(2, 24));
            border-radius: 50%;
            background: #222;
        }
    }

    &--error {
        color: shared.$color-no;
    }
}
</style>
