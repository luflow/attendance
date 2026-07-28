<template>
	<component
		:is="icon"
		:size="18"
		class="response-dot"
		:class="`response-dot--${getResponseVariant(response)}`"
		:title="getResponseText(response)" />
</template>

<script setup>
import { computed } from 'vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import { getResponseIcon, getResponseText, getResponseVariant } from '../../utils/response.js'

const props = defineProps({
	response: {
		type: String,
		required: true,
	},
})

// The same glyphs the answer buttons and the sidebar use, resolved through the
// shared helper so all three stay in step.
const ICONS = { CheckCircle, HelpCircle, CloseCircle }
const icon = computed(() => ICONS[getResponseIcon(props.response)] ?? HelpCircle)
</script>

<style scoped lang="scss">
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
            inset: percentage(calc(2 / 24));
            border-radius: 50%;
            background: #222;
        }
    }

    &--error {
        color: shared.$color-no;
    }
}
</style>
