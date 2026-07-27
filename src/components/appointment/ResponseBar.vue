<template>
	<div class="response-bar" data-test="response-bar">
		<div class="response-bar__track">
			<div
				v-for="segment in filledSegments"
				:key="segment.key"
				class="response-bar__segment"
				:class="`response-bar__segment--${segment.variant}`"
				:style="{ width: segment.width }" />
		</div>
		<div class="response-bar__legend">
			<span
				v-for="segment in segments"
				:key="segment.key"
				:data-test="`response-bar-legend-${segment.key}`">
				<strong :class="`response-bar__count--${segment.variant}`">{{ segment.count }}</strong>
				{{ segment.label }}
			</span>
			<slot name="trailing" />
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
	// [{ key, variant, count, label }] with variant in success | warning |
	// error | tertiary. A "tertiary" segment is legend-only — it is the empty
	// part of the track (nobody answered yet, check-in still pending).
	segments: {
		type: Array,
		required: true,
	},
})

const filledSegments = computed(() => {
	const total = props.segments.reduce((sum, segment) => sum + segment.count, 0)
	if (total === 0) return []
	return props.segments
		.filter((segment) => segment.variant !== 'tertiary' && segment.count > 0)
		.map((segment) => ({ ...segment, width: `${(segment.count / total) * 100}%` }))
})
</script>

<style scoped lang="scss">
@use "../../styles/shared.scss";

.response-bar {
    display: flex;
    flex-direction: column;
    gap: 7px;

    &__track {
        display: flex;
        height: 8px;
        border-radius: 99px;
        overflow: hidden;
        background: var(--color-background-dark);
    }

    &__segment {
        &--success {
            background: shared.$bar-yes;
        }

        &--warning {
            background: shared.$bar-maybe;
        }

        &--error {
            background: shared.$bar-no;
        }
    }

    &__legend {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        font-size: 13px;
        color: var(--color-text-maxcontrast);
    }

    &__count {
        &--success {
            color: var(--color-success-text, var(--color-success));
        }

        &--warning {
            color: var(--color-warning-text, var(--color-warning));
        }

        &--error {
            color: var(--color-error-text, var(--color-error));
        }
    }
}
</style>
