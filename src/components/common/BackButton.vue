<template>
	<NcButton variant="tertiary" data-test="button-back">
		<template #icon>
			<ArrowLeft :size="20" />
		</template>
		{{ t('attendance', 'Back') }}
	</NcButton>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
</script>
