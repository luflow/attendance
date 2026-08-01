<template>
	<NcPopover
		:shown="open"
		popupRole="dialog"
		class="set-answer-popover-wrapper"
		@update:shown="(value) => open = value">
		<template #trigger>
			<slot :pending="pending" />
		</template>
		<div class="set-answer-popover" role="dialog" aria-modal="true">
			<!-- TRANSLATORS: Popover title — a manager records the yes/no/maybe answer on behalf of {name} (e.g. after a phone call). -->
			<p>{{ t('attendance', 'Set answer for {name}', { name: displayName }) }}</p>
			<div class="set-answer-popover__options">
				<NcButton
					v-for="option in RESPONSE_ORDER"
					:key="option"
					:variant="option === currentResponse ? getResponseVariant(option) : 'secondary'"
					size="small"
					:disabled="pending"
					:data-test="`set-answer-${option}-${userId}`"
					@click="choose(option)">
					<template #icon>
						<component :is="ANSWER_ICONS[option]" :size="16" />
					</template>
					{{ getResponseText(option) }}
				</NcButton>
			</div>
			<NcButton
				v-if="currentResponse"
				class="set-answer-popover__clear"
				variant="tertiary"
				size="small"
				:disabled="pending"
				:data-test="`set-answer-clear-${userId}`"
				@click="choose(null)">
				<template #icon>
					<UndoVariantIcon :size="16" />
				</template>
				<!-- TRANSLATORS: Button in the set-answer popover — removes the person's answer so they count as "not yet responded" again. -->
				{{ t('attendance', 'Clear answer') }}
			</NcButton>
		</div>
	</NcPopover>
</template>

<script setup>
import { NcButton, NcPopover } from '@nextcloud/vue'
import { ref } from 'vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'
import UndoVariantIcon from 'vue-material-design-icons/UndoVariant.vue'
import { getResponseText, getResponseVariant, RESPONSE_ORDER } from '../../utils/response.js'

const props = defineProps({
	userId: {
		type: String,
		required: true,
	},
	displayName: {
		type: String,
		required: true,
	},
	// The person's current answer, or null when they have not responded yet.
	currentResponse: {
		type: String,
		default: null,
	},
	// An answer for this user is already being saved.
	pending: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['setAnswer'])

const ANSWER_ICONS = {
	yes: CheckCircleIcon,
	maybe: HelpCircleIcon,
	no: CloseCircleIcon,
}

const open = ref(false)

function choose(response) {
	open.value = false
	emit('setAnswer', props.userId, response)
}
</script>

<style scoped lang="scss">
.set-answer-popover-wrapper {
    display: inline-flex;
}
</style>

<style lang="scss">
// Rendered into the popover teleport target, so it cannot be scoped.
.set-answer-popover {
    padding: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;

    p {
        margin: 0 0 10px 0;
    }

    &__options {
        display: flex;
        gap: 6px;
    }

    &__clear {
        margin-top: 8px;
    }
}
</style>
